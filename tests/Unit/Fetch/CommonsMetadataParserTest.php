<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\CommonsMetadataParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Fetch\CommonsMetadataParser
 * @license GPL-2.0-or-later
 */
final class CommonsMetadataParserTest extends TestCase {

	public function testParsesExtmetadata(): void {
		$meta = CommonsMetadataParser::fromImageInfo( [
			'width' => 800,
			'height' => 600,
			'size' => 123456,
			'mime' => 'image/jpeg',
			'thumburl' => 'https://upload.wikimedia.org/thumb.jpg',
			'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:X.jpg',
			'extmetadata' => [
				'Artist' => [ 'value' => '<a href="//example.test/creator">Jane Doe</a>' ],
				'LicenseShortName' => [ 'value' => 'CC BY-SA 4.0' ],
				'ImageDescription' => [ 'value' => '<span>An <b>example</b> image.</span>' ],
				'ObjectName' => [ 'value' => 'Example.jpg' ],
				'Credit' => [ 'value' => 'Wikimedia Commons' ],
			],
		] );

		$this->assertSame( 'Jane Doe', $meta->author );
		$this->assertSame( 'CC BY-SA 4.0', $meta->licenseLabel );
		$this->assertSame( 'An example image.', $meta->description );
		$this->assertSame( 'Example.jpg', $meta->name );
		$this->assertSame( 'Wikimedia Commons', $meta->credit );
		$this->assertSame( 800, $meta->width );
		$this->assertSame( 600, $meta->height );
		$this->assertSame( 123456, $meta->fileSize );
		$this->assertSame( 'image/jpeg', $meta->mime );
		$this->assertSame( 'https://upload.wikimedia.org/thumb.jpg', $meta->thumbUrl );
		$this->assertSame( 'https://commons.wikimedia.org/wiki/File:X.jpg', $meta->sourceUrl );
	}

	public function testEmptyExtmetadataYieldsNulls(): void {
		$meta = CommonsMetadataParser::fromImageInfo( [ 'width' => 1, 'height' => 1 ] );
		$this->assertNull( $meta->author );
		$this->assertNull( $meta->licenseLabel );
		$this->assertNull( $meta->name );
	}

	public function testLongValuesAreCapped(): void {
		$meta = CommonsMetadataParser::fromImageInfo( [
			'extmetadata' => [
				'ImageDescription' => [ 'value' => str_repeat( 'x', 500 ) ],
			],
		] );
		$this->assertSame( 250, strlen( $meta->description ) );
	}

	public function testHtmlEntitiesAreDecoded(): void {
		$meta = CommonsMetadataParser::fromImageInfo( [
			'extmetadata' => [
				'Artist' => [ 'value' => 'Ren&eacute; &amp; Co.' ],
			],
		] );
		$this->assertSame( 'René & Co.', $meta->author );
	}
}
