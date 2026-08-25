<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * SSRF-guarded metadata fetch for an arbitrary contributor-entered image URL
 * (the "Validate" step's server-side path — used for NON-Wikimedia hosts,
 * and as the fallback when the browser-side Wikimedia fetch fails).
 *
 * Defence is layered, mirroring PageMetadataFetcher:
 *  1. SsrfGuard literal checks (scheme/hostname/IP-literal) — pure and
 *     deterministic;
 *  2. the transport (MediaWiki HttpRequestFactory with `rejectLocalUrls`)
 *     refuses private/loopback DNS resolution at connect time.
 *
 * Wikimedia hosts are resolved through the Commons `imageinfo` API when a
 * file title can be extracted (rich author/license/description metadata);
 * everything else gets a capped GET probe: response headers + the first
 * bytes, from which the image dimensions and MIME type are sniffed.
 *
 * Best-effort contract: never throws — a failure yields an ImageMetadata
 * with warnings (the user fills the form by hand).
 *
 * The transport is injectable for unit tests; the default is MW-bound and
 * only used in the running wiki.
 *
 * @license GPL-2.0-or-later
 */
final class UploadMetadataFetcher {

	/** Probe size: enough for the header of any common raster/SVG format. */
	private const PROBE_BYTES = 131072;

	/**
	 * Probe transport: returns the response headers + (capped) body.
	 *
	 * @var callable(string,float):array{status:int, headers:array<string,string[]>, body:string}|null
	 */
	private $transport;

	/**
	 * @param callable(string,float):array{status:int, headers:array<string,string[]>, body:string}|null $transport
	 */
	public function __construct( ?callable $transport = null ) {
		$this->transport = $transport;
	}

	public function fetch( string $url, float $timeout = 8.0 ): ImageMetadata {
		$originalUrl = $url;
		$url = SsrfGuard::validate( $url );
		if ( $url === null ) {
			return ImageMetadata::failure( $originalUrl, 'URL rejected by the SSRF guard' );
		}

		// Wikimedia special handling: the Commons API yields the author,
		// license and description directly. Best-effort — the generic probe
		// is the fallback when no title can be extracted.
		if ( WikimediaFileUrl::isWikimediaHost( $url ) ) {
			$query = WikimediaFileUrl::commonsQuery( $url );
			if ( $query !== null ) {
				$meta = $this->commonsImageInfo( $query['api'], $query['title'], $url, $timeout );
				if ( $meta !== null ) {
					return $meta;
				}
			}
		}

		return $this->probeGeneric( $url, $timeout );
	}

	/**
	 * Commons `imageinfo` (extmetadata|size) for a file title, or null when
	 * the API call fails or the title is missing.
	 */
	private function commonsImageInfo( string $api, string $title, string $sourceUrl, float $timeout ): ?ImageMetadata {
		try {
			$data = $this->getJson( $api, [
				'action' => 'query',
				'prop' => 'imageinfo',
				'iiprop' => 'extmetadata|size|url',
				'format' => 'json',
				'formatversion' => 2,
				'titles' => $title,
			], $timeout );
		} catch ( \Throwable $e ) {
			return null;
		}
		$info = $data['query']['pages'][0]['imageinfo'][0] ?? null;
		if ( !is_array( $info ) ) {
			return null;
		}
		$meta = CommonsMetadataParser::fromImageInfo( $info );
		return new ImageMetadata(
			name: $meta->name,
			description: $meta->description,
			author: $meta->author,
			licenseLabel: $meta->licenseLabel,
			credit: $meta->credit,
			width: $meta->width,
			height: $meta->height,
			fileSize: $meta->fileSize,
			mime: $meta->mime,
			thumbUrl: $meta->thumbUrl,
			sourceUrl: $sourceUrl,
			warnings: []
		);
	}

	/**
	 * Generic probe: one capped GET → response headers (MIME, Content-Length)
	 * + body header bytes → getimagesize() for the pixel dimensions (only
	 * meaningful for raster/vector images — non-image files report MIME +
	 * byte size without dimensions).
	 */
	private function probeGeneric( string $url, float $timeout ): ImageMetadata {
		$warnings = [];
		try {
			$response = $this->transport()
				? $this->transport()( $url, $timeout )
				: $this->defaultTransport()( $url, $timeout );
		} catch ( \Throwable $e ) {
			return ImageMetadata::failure( $url, 'fetch failed: ' . $e->getMessage() );
		}
		if ( ( $response['status'] ?? 0 ) !== 200 ) {
			return ImageMetadata::failure( $url, 'HTTP ' . ( $response['status'] ?? 'unknown' ) . ' — not fetchable' );
		}

		$headers = $response['headers'] ?? [];
		$body = (string)( $response['body'] ?? '' );
		$mime = $this->header( $headers, 'content-type' );
		if ( $mime === null && $body !== '' ) {
			$sniffed = @getimagesizefromstring( $body );
			$mime = is_array( $sniffed ) ? (string)( $sniffed['mime'] ?? '' ) : null;
		}

		$size = $this->header( $headers, 'content-length' );
		$fileSize = $size !== null && ctype_digit( $size ) ? (int)$size : null;
		if ( $fileSize === null ) {
			$warnings[] = 'server did not report a file size';
		}

		// Pixel dimensions only for image payloads (PDF/video/audio have
		// none); the MIME + byte size are reported for every file type.
		$width = null;
		$height = null;
		if ( $mime !== null && strpos( $mime, 'image/' ) === 0 ) {
			$dimensions = @getimagesizefromstring( $body );
			if ( is_array( $dimensions ) && isset( $dimensions[0], $dimensions[1] ) && $dimensions[0] > 0 && $dimensions[1] > 0 ) {
				$width = $dimensions[0];
				$height = $dimensions[1];
			} else {
				$warnings[] = 'could not read the image dimensions (the probe is capped at ' . self::PROBE_BYTES . ' bytes)';
			}
		}

		return new ImageMetadata(
			name: null, description: null, author: null, licenseLabel: null,
			credit: null, width: $width, height: $height, fileSize: $fileSize,
			mime: $mime, thumbUrl: null, sourceUrl: $url, warnings: $warnings
		);
	}

	/** @return callable(string,float):array{status:int, headers:array<string,string[]>, body:string}|null */
	private function transport(): ?callable {
		return $this->transport;
	}

	/**
	 * MediaWiki transport: HttpRequestFactory with rejectLocalUrls (DNS-level
	 * SSRF) + a capped read callback (aborts the transfer at PROBE_BYTES).
	 *
	 * @return array{status:int, headers:array<string,string[]>, body:string}
	 */
	private function defaultTransport(): callable {
		return static function ( string $url, float $timeout ): array {
			$http = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory()
				->create(
					$url,
					[
						'timeout' => (int)max( 1, (int)ceil( $timeout ) ),
						'connectTimeout' => (int)max( 1, min( 10, (int)ceil( $timeout ) ) ),
						'rejectLocalUrls' => true,
					],
					__METHOD__
				);
			$body = '';
			// setCallback aborts the fetch when the callback returns fewer
			// bytes than it was handed — return 0 past the probe cap so a
			// multi-GB image is never downloaded, only its header.
			$http->setCallback( static function ( $fh, string $buffer ) use ( &$body ): int {
				if ( strlen( $body ) + strlen( $buffer ) > self::PROBE_BYTES ) {
					return 0;
				}
				$body .= $buffer;
				return strlen( $buffer );
			} );
			$status = $http->execute();
			if ( !$status->isOK() ) {
				throw new \RuntimeException( 'HTTP ' . $status->getMessage()->getKey() );
			}
			return [
				'status' => $http->getStatus(),
				'headers' => $http->getResponseHeaders(),
				'body' => $body,
			];
		};
	}

	/**
	 * @param array<string,string[]> $headers
	 * @return string|null first value of a (lowercase-name) header
	 */
	private function header( array $headers, string $name ): ?string {
		$value = $headers[$name] ?? null;
		if ( is_array( $value ) && $value !== [] ) {
			return (string)$value[0];
		}
		return null;
	}

	/**
	 * JSON GET for the Commons API (the CORS-open read path is for browsers;
	 * the server reads it directly through the same endpoint).
	 *
	 * @return array<string,mixed>
	 */
	private function getJson( string $url, array $query, float $timeout ): array {
		$transport = $this->transport() ?? $this->defaultTransport();
		$sep = strpos( $url, '?' ) === false ? '?' : '&';
		$response = $transport( $url . $sep . http_build_query( $query ), $timeout );
		if ( ( $response['status'] ?? 0 ) !== 200 ) {
			throw new \RuntimeException( 'HTTP ' . ( $response['status'] ?? 'unknown' ) );
		}
		$decoded = json_decode( (string)( $response['body'] ?? '' ), true, 512, JSON_THROW_ON_ERROR );
		if ( !is_array( $decoded ) ) {
			throw new \RuntimeException( 'unexpected JSON payload' );
		}
		return $decoded;
	}
}
