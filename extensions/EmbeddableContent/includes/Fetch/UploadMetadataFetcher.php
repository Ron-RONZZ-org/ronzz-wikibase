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
				// `mime` must be requested explicitly — without it the
				// imageinfo payload carries no MIME type and the validate
				// preview cannot distinguish images from other file types.
				'iiprop' => 'extmetadata|size|url|mime',
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
			$dims = self::readImageDimensions( $body );
			if ( $dims !== null ) {
				[ $width, $height ] = $dims;
			} else {
				// The body may be COMPLETE (a format getimagesize + the
				// format parsers do not know) or capped — only blame the cap
				// when the transfer was actually truncated.
				$warnings[] = strlen( $body ) >= self::PROBE_BYTES
					? 'could not read the image dimensions (the probe is capped at ' . self::PROBE_BYTES . ' bytes)'
					: 'could not read the image dimensions';
			}
		}

		return new ImageMetadata(
			name: null, description: null, author: null, licenseLabel: null,
			credit: null, width: $width, height: $height, fileSize: $fileSize,
			mime: $mime, thumbUrl: null, sourceUrl: $url, warnings: $warnings
		);
	}

	/**
	 * Pixel dimensions of an image body, or null. getimagesize() handles the
	 * complete headers of the common raster formats; the format parsers below
	 * work on the CAPPED probe bytes where getimagesize cannot:
	 *
	 *  - SVG   — PHP's getimagesize has no SVG support at all (the reported
	 *            "could not read the image dimensions (the probe is capped
	 *            at 131072 bytes)" for logo URLs);
	 *  - JPEG  — a huge EXIF APP1 segment (embedded thumbnail) pushes the
	 *            SOF marker past the probe cap; the marker scan reads it
	 *            wherever it is within the body;
	 *  - PNG/GIF/BMP/WebP — header parses tolerant of a truncated body.
	 *
	 * @return array{0:int,1:int}|null [width, height]
	 */
	private static function readImageDimensions( string $body ): ?array {
		$sniffed = @getimagesizefromstring( $body );
		if ( is_array( $sniffed ) && ( $sniffed[0] ?? 0 ) > 0 && ( $sniffed[1] ?? 0 ) > 0 ) {
			return [ (int)$sniffed[0], (int)$sniffed[1] ];
		}
		return self::pngDimensions( $body )
			?? self::gifDimensions( $body )
			?? self::bmpDimensions( $body )
			?? self::webpDimensions( $body )
			?? self::jpegDimensions( $body )
			?? self::svgDimensions( $body );
	}

	/** @return array{0:int,1:int}|null */
	private static function pngDimensions( string $body ): ?array {
		if ( strlen( $body ) < 24 || substr( $body, 0, 8 ) !== "\x89PNG\r\n\x1a\n" ) {
			return null;
		}
		$ihdr = unpack( 'Nwidth/Nheight', substr( $body, 16, 8 ) );
		return is_array( $ihdr ) && $ihdr['width'] > 0 && $ihdr['height'] > 0
			? [ (int)$ihdr['width'], (int)$ihdr['height'] ] : null;
	}

	/** @return array{0:int,1:int}|null */
	private static function gifDimensions( string $body ): ?array {
		if ( strlen( $body ) < 10 || !in_array( substr( $body, 0, 6 ), [ 'GIF87a', 'GIF89a' ], true ) ) {
			return null;
		}
		$desc = unpack( 'vwidth/vheight', substr( $body, 6, 4 ) );
		return is_array( $desc ) && $desc['width'] > 0 && $desc['height'] > 0
			? [ (int)$desc['width'], (int)$desc['height'] ] : null;
	}

	/** @return array{0:int,1:int}|null */
	private static function bmpDimensions( string $body ): ?array {
		if ( strlen( $body ) < 26 || substr( $body, 0, 2 ) !== 'BM' ) {
			return null;
		}
		$hdr = unpack( 'Vwidth/Vheight', substr( $body, 18, 8 ) );
		// The height field is signed (negative = top-down rows).
		return is_array( $hdr ) && $hdr['width'] > 0 && abs( $hdr['height'] ) > 0
			? [ (int)$hdr['width'], abs( (int)$hdr['height'] ) ] : null;
	}

	/** @return array{0:int,1:int}|null */
	private static function webpDimensions( string $body ): ?array {
		if ( strlen( $body ) < 30 || substr( $body, 0, 4 ) !== 'RIFF' || substr( $body, 8, 4 ) !== 'WEBP' ) {
			return null;
		}
		$chunk = substr( $body, 12, 4 );
		$byte = static function ( int $i ) use ( $body ): int {
			return ord( $body[$i] ?? "\0" );
		};
		if ( $chunk === 'VP8X' ) {
			// Extended: canvas width-1 / height-1 as 24-bit little-endian.
			$w = $byte( 24 ) | ( $byte( 25 ) << 8 ) | ( $byte( 26 ) << 16 );
			$h = $byte( 27 ) | ( $byte( 28 ) << 8 ) | ( $byte( 29 ) << 16 );
			return $w > 0 && $h > 0 ? [ $w + 1, $h + 1 ] : null;
		}
		if ( $chunk === 'VP8L' ) {
			// Lossless: 32-bit little-endian at offset 21; 14-bit w-1 / h-1.
			$v = $byte( 21 ) | ( $byte( 22 ) << 8 ) | ( $byte( 23 ) << 16 ) | ( $byte( 24 ) << 24 );
			$w = ( $v & 0x3FFF ) + 1;
			$h = ( ( $v >> 14 ) & 0x3FFF ) + 1;
			return $w > 0 && $h > 0 ? [ $w, $h ] : null;
		}
		if ( $chunk === 'VP8 ' ) {
			// Lossy: frame tag (3) + sync code (3) + 14-bit w/h at offset 23.
			$w = ( $byte( 23 ) | ( $byte( 24 ) << 8 ) ) & 0x3FFF;
			$h = ( $byte( 25 ) | ( $byte( 26 ) << 8 ) ) & 0x3FFF;
			return $w > 0 && $h > 0 ? [ $w, $h ] : null;
		}
		return null;
	}

	/**
	 * JPEG SOF marker scan. The marker chain starts right after the SOI
	 * (FFD8); each segment is FF <marker> <2-byte length> <data>. The SOF
	 * markers (C0-CF, minus C4/C8/CC which are table segments) carry the
	 * height/width right after the precision byte. Works on a PARTIAL body:
	 * the scan stops at the first truncated segment.
	 *
	 * @return array{0:int,1:int}|null
	 */
	private static function jpegDimensions( string $body ): ?array {
		if ( strlen( $body ) < 4 || substr( $body, 0, 2 ) !== "\xFF\xD8" ) {
			return null;
		}
		$len = strlen( $body );
		$i = 2;
		while ( $i + 3 < $len ) {
			if ( $body[$i] !== "\xFF" ) {
				return null; // lost sync — not a JPEG structure we know
			}
			$marker = ord( $body[$i + 1] );
			if ( $marker === 0xD9 || $marker === 0xDA ) {
				return null; // EOI / start of scan — no SOF seen
			}
			// Standalone markers (RSTn / TEM / SOI) carry no length.
			if ( ( $marker >= 0xD0 && $marker <= 0xD7 ) || $marker === 0x01 ) {
				$i += 2;
				continue;
			}
			$seg = unpack( 'nlength', substr( $body, $i + 2, 2 ) );
			if ( !is_array( $seg ) ) {
				return null;
			}
			$segLen = (int)$seg['length'];
			if ( $segLen < 2 || $i + 2 + $segLen > $len ) {
				return null; // truncated segment — the cap cut it here
			}
			// SOF0..SOF15 (C0-CF) except the table markers C4 (DHT), C8
			// (JPG) and CC (DAC).
			if ( $marker >= 0xC0 && $marker <= 0xCF && !in_array( $marker, [ 0xC4, 0xC8, 0xCC ], true ) ) {
				if ( $segLen < 7 ) {
					return null;
				}
				$sofs = unpack( 'nheight/nwidth', substr( $body, $i + 5, 4 ) );
				if ( is_array( $sofs ) && $sofs['width'] > 0 && $sofs['height'] > 0 ) {
					return [ (int)$sofs['width'], (int)$sofs['height'] ];
				}
				return null;
			}
			$i += 2 + $segLen;
		}
		return null;
	}

	/**
	 * SVG: the <svg> opening tag's width/height attributes (any CSS unit) or
	 * its viewBox. getimagesize() has no SVG support — this is the common
	 * "logo URL" failure the reported warning came from.
	 *
	 * @return array{0:int,1:int}|null
	 */
	private static function svgDimensions( string $body ): ?array {
		if ( stripos( $body, '<svg' ) === false ) {
			return null;
		}
		if ( preg_match( '/<svg\b[^>]*>/is', $body, $m ) !== 1 ) {
			return null;
		}
		$tag = $m[0];
		$w = self::svgLength( $tag, 'width' );
		$h = self::svgLength( $tag, 'height' );
		if ( $w !== null && $h !== null ) {
			return [ $w, $h ];
		}
		if ( preg_match( '/\bviewBox\s*=\s*["\']\s*[-\d.eE+,\s]+\s*["\']/i', $tag, $vm ) === 1 ) {
			$numbers = preg_split( '/[\s,]+/', trim( $vm[0], "\"' " ) );
			if ( is_array( $numbers ) && count( $numbers ) >= 4 ) {
				$vw = (int)round( (float)$numbers[2] );
				$vh = (int)round( (float)$numbers[3] );
				if ( $vw > 0 && $vh > 0 ) {
					return [ $vw, $vh ];
				}
			}
		}
		return null;
	}

	/**
	 * Numeric value of an SVG width/height attribute (CSS units stripped),
	 * or null.
	 */
	private static function svgLength( string $tag, string $attr ): ?int {
		if ( preg_match(
			'/\b' . $attr . '\s*=\s*["\']\s*([-+]?[0-9]*\.?[0-9]+(?:[eE][-+]?[0-9]+)?)\s*(?:px|pt|pc|cm|mm|in|em|ex|%)?\s*["\']/i',
			$tag,
			$m
		) !== 1 ) {
			return null;
		}
		$v = (int)round( (float)$m[1] );
		return $v > 0 ? $v : null;
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
