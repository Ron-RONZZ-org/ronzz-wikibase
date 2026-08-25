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
		$this->assertSame( 'example.jpg', $meta->name );
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

	public function testLongDescriptionIsCappedAtDescriptionCap(): void {
		$meta = CommonsMetadataParser::fromImageInfo( [
			'extmetadata' => [
				'ImageDescription' => [ 'value' => str_repeat( 'x', 5000 ) ],
			],
		] );
		$this->assertSame( 2000, strlen( $meta->description ) );
	}

	public function testDescriptionIsNotCappedAtShortTextCap(): void {
		// A fetched summary between 250 and 2000 chars must survive intact
		// (the reported regression: the NGS description is 986 chars and was
		// cut at 250).
		$text = implode( ' ', array_fill( 0, 300, 'word' ) );
		$meta = CommonsMetadataParser::fromImageInfo( [
			'extmetadata' => [
				'ImageDescription' => [ 'value' => $text ],
			],
		] );
		$this->assertSame( strlen( $text ), strlen( $meta->description ) );
	}

	public function testDescriptionTruncatesAtSentenceBoundary(): void {
		// A >2000-char description must not end mid-sentence: the cut lands
		// on the last sentence-ending punctuation inside the cap.
		$longSentence = 'This is a deliberately long first sentence that goes on and on beyond one'
			. ' hundred characters so the sentence boundary sits well past the minimum cut'
			. ' position inside the cap. ';
		$shortSentence = 'Second sentence here.';
		$filler = ' padding padding padding padding padding padding padding padding padding padding';
		$meta = CommonsMetadataParser::fromImageInfo( [
			'extmetadata' => [
				'ImageDescription' => [ 'value' => $longSentence . $shortSentence . str_repeat( $filler, 30 ) ],
			],
		] );
		$desc = $meta->description;
		$this->assertLessThanOrEqual( 2000, strlen( $desc ) );
		$this->assertStringEndsWith( '.', $desc );
		$this->assertStringContainsString( 'Second sentence here.', $desc );
		$this->assertStringNotContainsString( ' padding', substr( $desc, -50 ) );
	}

	public function testShortTextFieldsStayCappedAt250(): void {
		$meta = CommonsMetadataParser::fromImageInfo( [
			'extmetadata' => [
				'Artist' => [ 'value' => str_repeat( 'a', 500 ) ],
				'Credit' => [ 'value' => str_repeat( 'c', 500 ) ],
				'LicenseShortName' => [ 'value' => str_repeat( 'l', 500 ) ],
			],
		] );
		$this->assertSame( 250, strlen( $meta->author ) );
		$this->assertSame( 250, strlen( $meta->credit ) );
		$this->assertSame( 250, strlen( $meta->licenseLabel ) );
	}

	public function testObjectNameIsNormalizedToDestName(): void {
		$meta = CommonsMetadataParser::fromImageInfo( [
			'extmetadata' => [
				'ObjectName' => [ 'value' => 'National Geographic Society Administration Building' ],
			],
		] );
		$this->assertSame( 'national-geographic-society-administration-building', $meta->name );
	}

	public function testDestNamePreservesLowercasedExtension(): void {
		$meta = CommonsMetadataParser::fromImageInfo( [
			'extmetadata' => [
				'ObjectName' => [ 'value' => 'Example File.JPG' ],
			],
		] );
		$this->assertSame( 'example-file.jpg', $meta->name );
	}

	public function testDestNameStripsIllegalFilenameChars(): void {
		$meta = CommonsMetadataParser::fromImageInfo( [
			'extmetadata' => [
				'ObjectName' => [ 'value' => 'A: B# [C] {D} |E|' ],
			],
		] );
		$this->assertSame( 'a-b-c-d-e', $meta->name );
	}

	public function testDestNameEmptyWhenNothingUsable(): void {
		$meta = CommonsMetadataParser::fromImageInfo( [
			'extmetadata' => [
				'ObjectName' => [ 'value' => '###' ],
			],
		] );
		$this->assertNull( $meta->name );
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
