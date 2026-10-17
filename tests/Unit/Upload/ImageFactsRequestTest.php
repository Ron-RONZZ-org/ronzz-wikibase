<?php

declare( strict_types = 1 );

namespace Tests\Unit\Upload;

use EmbeddableContent\Upload\ImageFactsRequest;
use PHPUnit\Framework\TestCase;

/**
 * Pure request-shape helpers for action=imagefacts.
 *
 * @covers \EmbeddableContent\Upload\ImageFactsRequest
 * @license GPL-2.0-or-later
 */
final class ImageFactsRequestTest extends TestCase {

	public function testNormalizeStripsFilePrefix(): void {
		$this->assertSame( 'Cat.jpg', ImageFactsRequest::normalizeFileName( 'File:Cat.jpg' ) );
		$this->assertSame( 'Cat.jpg', ImageFactsRequest::normalizeFileName( 'file: Cat.jpg' ) );
	}

	public function testNormalizeConvertsUnderscoresAndWhitespace(): void {
		$this->assertSame(
			'Universe timeline.svg',
			ImageFactsRequest::normalizeFileName( '  File:Universe__timeline.svg  ' )
		);
	}

	public function testNormalizeRejectsEmptyInput(): void {
		$this->assertNull( ImageFactsRequest::normalizeFileName( '' ) );
		$this->assertNull( ImageFactsRequest::normalizeFileName( 'File:   ' ) );
	}

	public function testLicenseIdValidation(): void {
		$this->assertTrue( ImageFactsRequest::isValidLicenseId( 'Q124' ) );
		$this->assertFalse( ImageFactsRequest::isValidLicenseId( 'Q0' ) );
		$this->assertFalse( ImageFactsRequest::isValidLicenseId( 'Q0124' ) );
		$this->assertFalse( ImageFactsRequest::isValidLicenseId( 'CC BY-SA 4.0' ) );
		$this->assertFalse( ImageFactsRequest::isValidLicenseId( 'q124' ) );
	}

	public function testParseClearFieldsTrimsAndDeduplicates(): void {
		$this->assertSame(
			[ 'license', 'licenseInfo' ],
			ImageFactsRequest::parseClearFields( ' license, licenseInfo , license , ' )
		);
	}

	public function testParseClearFieldsEmpty(): void {
		$this->assertSame( [], ImageFactsRequest::parseClearFields( '' ) );
		$this->assertSame( [], ImageFactsRequest::parseClearFields( ' , ' ) );
	}

	public function testIsClearableField(): void {
		$this->assertTrue( ImageFactsRequest::isClearableField( 'license' ) );
		$this->assertTrue( ImageFactsRequest::isClearableField( 'sourceUrl' ) );
		$this->assertFalse( ImageFactsRequest::isClearableField( 'image' ) );
		$this->assertFalse( ImageFactsRequest::isClearableField( 'bogus' ) );
	}
}
