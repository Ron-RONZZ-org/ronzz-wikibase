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
}
