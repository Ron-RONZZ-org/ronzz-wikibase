<?php

declare( strict_types = 1 );

namespace Tests\Unit\Upload;

use EmbeddableContent\Upload\ImageUploadHelper;
use PHPUnit\Framework\TestCase;

/**
 * Pure parts of the shared portrait/logo upload helper. The MW-bound upload
 * path (handleUpload / UploadFromUrl) is covered by the dev-stack page-flow
 * E2E, per the repo's testing conventions.
 *
 * @covers \EmbeddableContent\Upload\ImageUploadHelper
 * @license GPL-2.0-or-later
 */
final class ImageUploadHelperTest extends TestCase {

	public function testDestNameUsesOriginalExtension(): void {
		$name = ImageUploadHelper::destName(
			'Ada Lovelace',
			'portrait',
			'Photo 01.PNG',
			null,
			ImageUploadHelper::IMAGE_EXTENSIONS
		);
		$this->assertSame( 'Ada Lovelace-portrait.png', $name );
	}

	public function testDestNameSanitizesLabel(): void {
		$name = ImageUploadHelper::destName(
			'ACME: The#Brand [v1]',
			'logo',
			'logo.svg',
			null,
			ImageUploadHelper::IMAGE_EXTENSIONS
		);
		$this->assertSame( 'ACME TheBrand v1-logo.svg', $name );
	}

	public function testDestNameMimeExtensionFallback(): void {
		// The URL's name has no extension — the MIME-resolved extension wins.
		$name = ImageUploadHelper::destName(
			'Flameshot',
			'logo',
			'logo',
			'png',
			ImageUploadHelper::IMAGE_EXTENSIONS
		);
		$this->assertSame( 'Flameshot-logo.png', $name );
	}

	public function testDestNameRejectsUnsupportedExtension(): void {
		$name = ImageUploadHelper::destName(
			'Report',
			'logo',
			'document.pdf',
			null,
			ImageUploadHelper::IMAGE_EXTENSIONS
		);
		$this->assertSame( '', $name );
	}

	public function testDestNameRejectsEmptyLabel(): void {
		$name = ImageUploadHelper::destName(
			'   ',
			'portrait',
			'x.jpg',
			null,
			ImageUploadHelper::IMAGE_EXTENSIONS
		);
		$this->assertSame( '', $name );
	}

	public function testPageTextWithSource(): void {
		$text = ImageUploadHelper::pageText( 'Ada Lovelace', 'portrait', 'Special:AddPerson', 'https://upload.wikimedia.org/x.jpg' );
		$this->assertStringContainsString( 'Portrait of Ada Lovelace, uploaded via Special:AddPerson.', $text );
		$this->assertStringContainsString( 'Source: https://upload.wikimedia.org/x.jpg', $text );
	}

	public function testPageTextWithoutSource(): void {
		$text = ImageUploadHelper::pageText( 'Flameshot', 'logo', 'Special:AddSoftware' );
		$this->assertStringContainsString( 'Logo of Flameshot, uploaded via Special:AddSoftware.', $text );
		$this->assertStringNotContainsString( 'Source:', $text );
	}

	public function testWiringSpanCarriesValidConfig(): void {
		$span = ImageUploadHelper::wiringSpan( 'portrait' );
		$this->assertStringContainsString( 'class="wb-uploadmeta"', $span );
		$this->assertStringContainsString( 'data-config=', $span );

		preg_match( '/data-config="([^"]+)"/', $span, $m );
		$this->assertNotEmpty( $m );
		$config = json_decode( html_entity_decode( $m[1] ), true );
		$this->assertSame( 'wpportraitUrl', $config['urlField'] );
		$this->assertSame( 'wpportraitFile', $config['fileField'] );
		$this->assertSame( 'wpportraitMode', $config['modeField'] );
		$this->assertSame( 'wpportraitAuthor', $config['targets']['author'] );
		$this->assertSame( 'wpportraitLicense', $config['targets']['license'] );
		$this->assertSame( 'wpportraitLicenseInfo', $config['targets']['licenseInfo'] );
	}
}
