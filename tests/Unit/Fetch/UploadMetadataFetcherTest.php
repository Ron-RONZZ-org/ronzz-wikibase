<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\UploadMetadataFetcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Fetch\UploadMetadataFetcher
 * @license GPL-2.0-or-later
 */
final class UploadMetadataFetcherTest extends TestCase {

	/** 1x1 PNG (image header only — getimagesize parses it). */
	private const PNG_BODY = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	/** @return array{status:int,headers:array<string,string[]>,body:string} */
	private function transport( string $contentType, string $body, ?string $contentLength = null ): callable {
		return static function ( string $url, float $timeout ) use ( $contentType, $body, $contentLength ): array {
			return [
				'status' => 200,
				'headers' => [
					'content-type' => [ $contentType ],
					'content-length' => $contentLength !== null ? [ $contentLength ] : [],
				],
				'body' => $body,
			];
		};
	}

	public function testGenericProbeExtractsDimensions(): void {
		$png = base64_decode( self::PNG_BODY );
		$fetcher = new UploadMetadataFetcher( $this->transport( 'image/png', $png, '1024' ) );
		$meta = $fetcher->fetch( 'https://cdn.example.com/logo.png' );

		$this->assertSame( 1, $meta->width );
		$this->assertSame( 1, $meta->height );
		$this->assertSame( 'image/png', $meta->mime );
		$this->assertSame( 1024, $meta->fileSize );
		$this->assertSame( [], $meta->warnings );
		$this->assertSame( 'https://cdn.example.com/logo.png', $meta->sourceUrl );
	}

	public function testSvgBodyDimensionsParsedWithoutWarning(): void {
		// SVG is the reported "logo URL" failure: PHP's getimagesize has no
		// SVG support, so the generic probe warned "could not read the image
		// dimensions (the probe is capped at 131072 bytes)" even for a
		// complete small SVG. The format-aware parser reads width/height.
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630">'
			. '<rect width="1200" height="630" fill="#fff"/></svg>';
		$fetcher = new UploadMetadataFetcher( $this->transport( 'image/svg+xml', $svg, '128' ) );
		$meta = $fetcher->fetch( 'https://cdn.example.com/logo.svg' );

		$this->assertSame( 1200, $meta->width );
		$this->assertSame( 630, $meta->height );
		$this->assertSame( 'image/svg+xml', $meta->mime );
		$this->assertSame( [], $meta->warnings );
	}

	public function testSvgViewBoxDimensionsUsedWhenNoWidthHeight(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480"></svg>';
		$fetcher = new UploadMetadataFetcher( $this->transport( 'image/svg+xml', $svg, '64' ) );
		$meta = $fetcher->fetch( 'https://cdn.example.com/logo.svg' );

		$this->assertSame( 640, $meta->width );
		$this->assertSame( 480, $meta->height );
		$this->assertSame( [], $meta->warnings );
	}

	public function testTruncatedPngHeaderStillYieldsDimensions(): void {
		// The probe body is CAPPED at PROBE_BYTES — a PNG whose IHDR is within
		// the first bytes must report dimensions even when the body is only a
		// header slice (getimagesize on the truncated body fails).
		$png = base64_decode( self::PNG_BODY );
		$header = substr( $png, 0, 33 );
		$fetcher = new UploadMetadataFetcher( $this->transport( 'image/png', $header, '50000' ) );
		$meta = $fetcher->fetch( 'https://cdn.example.com/big.png' );

		$this->assertSame( 1, $meta->width );
		$this->assertSame( 1, $meta->height );
		$this->assertSame( [], $meta->warnings );
	}

	public function testWebpDimensionsParsed(): void {
		// A minimal WebP (VP8X extended) header with a 300x200 canvas.
		// Layout: "RIFF" <size=file-8> "WEBP" "VP8X" <chunk-size> then the
		// payload: flags(1) reserved(3) canvas-width-1(3) canvas-height-1(3)
		// reserved(1) — 24-bit little-endian canvas fields.
		$webp = "RIFF"
			. pack( 'V', 31 - 8 )   // RIFF chunk size = file length - 8
			. 'WEBPVP8X'
			. pack( 'V', 10 )       // VP8X chunk size
			. "\x00"                // flags
			. "\x00\x00\x00"        // reserved
			. "\x2b\x01\x00"        // width-1 = 299
			. "\xc7\x00\x00"        // height-1 = 199
			. "\x00";               // reserved
		$fetcher = new UploadMetadataFetcher( $this->transport( 'image/webp', $webp, '31' ) );
		$meta = $fetcher->fetch( 'https://cdn.example.com/logo.webp' );

		$this->assertSame( 300, $meta->width );
		$this->assertSame( 200, $meta->height );
		$this->assertSame( [], $meta->warnings );
	}

	public function testTruncatedBodyBeyondCapWarnsWithCapReason(): void {
		// A >PROBE_BYTES body whose dimensions cannot be read (garbage bytes
		// after a plausible MIME) — the warning must name the cap.
		$body = str_repeat( "\x00\x01\x02", 50000 );
		$fetcher = new UploadMetadataFetcher( $this->transport( 'image/png', $body, '999999' ) );
		$meta = $fetcher->fetch( 'https://cdn.example.com/odd.png' );

		$this->assertNull( $meta->width );
		$this->assertNotSame( [], $meta->warnings );
		$this->assertStringContainsString( 'capped', $meta->warnings[0] );
	}

	public function testNonImageContentTypeKeepsMimeAndSize(): void {
		// Any file type (PDF/video/audio/HTML) must be reported with its MIME
		// + byte size — the "all file types" support; only pixel dimensions
		// are image-specific.
		$fetcher = new UploadMetadataFetcher( $this->transport( 'application/pdf', '%PDF-1.4 test', '2048' ) );
		$meta = $fetcher->fetch( 'https://cdn.example.com/doc.pdf' );

		$this->assertNull( $meta->width );
		$this->assertNull( $meta->height );
		$this->assertSame( 'application/pdf', $meta->mime );
		$this->assertSame( 2048, $meta->fileSize );
		$this->assertSame( [], $meta->warnings );
	}

	public function testNonImageWithoutContentLengthWarnsOnlyOnSize(): void {
		$fetcher = new UploadMetadataFetcher( $this->transport( 'video/mp4', 'fake-mp4-bytes' ) );
		$meta = $fetcher->fetch( 'https://cdn.example.com/clip.mp4' );

		$this->assertNull( $meta->width );
		$this->assertNull( $meta->fileSize );
		$this->assertSame( 'video/mp4', $meta->mime );
		$this->assertNotSame( [], $meta->warnings );
	}

	public function testMissingContentLengthWarnsButKeepsDimensions(): void {
		$png = base64_decode( self::PNG_BODY );
		$fetcher = new UploadMetadataFetcher( $this->transport( 'image/png', $png ) );
		$meta = $fetcher->fetch( 'https://cdn.example.com/logo.png' );

		$this->assertSame( 1, $meta->width );
		$this->assertNull( $meta->fileSize );
		$this->assertNotSame( [], $meta->warnings );
	}

	public function testUnsafeUrlIsRejectedWithoutTransportCall(): void {
		$called = false;
		$transport = static function () use ( &$called ): array {
			$called = true;
			return [ 'status' => 200, 'headers' => [], 'body' => '' ];
		};
		$fetcher = new UploadMetadataFetcher( $transport );

		$meta = $fetcher->fetch( 'http://127.0.0.1/secret.png' );
		$this->assertFalse( $called, 'SSRF guard must reject before any request' );
		$this->assertNotSame( [], $meta->warnings );
	}

	public function testWikimediaUrlResolvesThroughCommonsApi(): void {
		$png = base64_decode( self::PNG_BODY );
		$transport = static function ( string $url, float $timeout ) use ( $png ): array {
			if ( strpos( $url, 'commons.wikimedia.org' ) !== false ) {
				return [
					'status' => 200,
					'headers' => [ 'content-type' => [ 'application/json' ] ],
					'body' => json_encode( [
						'query' => [
							'pages' => [ [
								'imageinfo' => [ [
									'width' => 1200,
									'height' => 800,
									'size' => 98765,
									'mime' => 'image/jpeg',
									'thumburl' => 'https://upload.wikimedia.org/thumb.jpg',
									'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:X.jpg',
									'extmetadata' => [
										'Artist' => [ 'value' => 'Jane Doe' ],
										'LicenseShortName' => [ 'value' => 'CC BY-SA 4.0' ],
										'ImageDescription' => [ 'value' => 'The example image' ],
									],
								] ],
							] ],
						],
					] ),
				];
			}
			throw new \RuntimeException( 'transport should only hit commons' );
		};

		$fetcher = new UploadMetadataFetcher( $transport );
		$meta = $fetcher->fetch( 'https://en.wikipedia.org/wiki/File:X.jpg' );

		$this->assertSame( 'Jane Doe', $meta->author );
		$this->assertSame( 'CC BY-SA 4.0', $meta->licenseLabel );
		$this->assertSame( 'The example image', $meta->description );
		$this->assertSame( 1200, $meta->width );
		$this->assertSame( 98765, $meta->fileSize );
		$this->assertSame( 'https://en.wikipedia.org/wiki/File:X.jpg', $meta->sourceUrl );
	}

	public function testWikimediaUrlWithMissingTitleFallsBackToProbe(): void {
		$png = base64_decode( self::PNG_BODY );
		// en.wikipedia.org/wiki/Albert_Einstein is not a File: reference —
		// the generic probe must kick in.
		$fetcher = new UploadMetadataFetcher( $this->transport( 'image/png', $png, '10' ) );
		$meta = $fetcher->fetch( 'https://en.wikipedia.org/wiki/Albert_Einstein' );
		$this->assertSame( 1, $meta->width );
	}

	public function testWikimediaUrlWithEncodedNameAndQueryParamsResolvesThroughCommonsApi(): void {
		// The reporter's exact URL shape: an upload.wikimedia.org /thumb/
		// URL with a percent-encoded file name ("%28"/"%29") plus the WMF
		// parser tracking query params. The extracted title must be DECODED
		// before the Commons query — the literal "%28" was double-encoded
		// into "%2528" in the API request and matched no file, so the fetch
		// fell back to the server-side probe and drew Wikimedia's 429/403
		// ("fetch failed: HTTP http-bad-status").
		$png = base64_decode( self::PNG_BODY );
		$captured = null;
		$transport = static function ( string $url, float $timeout ) use ( $png, &$captured ): array {
			$captured = $url;
			return [
				'status' => 200,
				'headers' => [ 'content-type' => [ 'application/json' ] ],
				'body' => json_encode( [
					'query' => [ 'pages' => [ [ 'imageinfo' => [ [
						'width' => 1360,
						'height' => 1813,
						'size' => 123456,
						'mime' => 'image/jpeg',
						'thumburl' => 'https://upload.wikimedia.org/thumb.jpg',
						'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Magnus-manske-2024 (cropped).jpg',
						'extmetadata' => [
							'Artist' => [ 'value' => 'Magnus Manske' ],
							'LicenseShortName' => [ 'value' => 'CC BY-SA 4.0' ],
						],
					] ] ] ] ],
				] ),
			];
		};

		$fetcher = new UploadMetadataFetcher( $transport );
		$meta = $fetcher->fetch(
			'https://upload.wikimedia.org/wikipedia/commons/thumb/c/c0/Magnus-manske-2024_%28cropped%29.jpg/250px-Magnus-manske-2024_%28cropped%29.jpg?utm_source=fr.wikipedia.org&utm_campaign=parser&utm_content=thumbnail'
		);

		$this->assertSame( 'Magnus Manske', $meta->author );
		$this->assertSame( 'CC BY-SA 4.0', $meta->licenseLabel );
		// The Commons titles query carries the DECODED title (parentheses
		// single-encoded by http_build_query) — never the literal "%28".
		$this->assertStringContainsString( 'titles=File%3AMagnus-manske-2024+%28cropped%29.jpg', $captured );
		$this->assertStringNotContainsString( '%2528', $captured );
	}
}
