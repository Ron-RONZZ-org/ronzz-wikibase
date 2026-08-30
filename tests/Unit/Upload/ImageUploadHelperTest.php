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
		// Whitespace in the label is normalized to a dash (issue report:
		// "File:European Space Agency-logo.png" → "European-Space-Agency-…").
		$this->assertSame( 'Ada-Lovelace-portrait.png', $name );
	}

	public function testDestNameSanitizesLabel(): void {
		$name = ImageUploadHelper::destName(
			'ACME: The#Brand [v1]',
			'logo',
			'logo.svg',
			null,
			ImageUploadHelper::IMAGE_EXTENSIONS
		);
		$this->assertSame( 'ACME-TheBrand-v1-logo.svg', $name );
	}

	public function testDestNameNormalizesRepeatedWhitespace(): void {
		$name = ImageUploadHelper::destName(
			"European   Space\tAgency",
			'logo',
			'logo.png',
			null,
			ImageUploadHelper::IMAGE_EXTENSIONS
		);
		$this->assertSame( 'European-Space-Agency-logo.png', $name );
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
		$text = ImageUploadHelper::pageText( 'Ada Lovelace', 'portrait', 'https://upload.wikimedia.org/x.jpg' );
		$this->assertStringContainsString( 'Portrait of Ada Lovelace.', $text );
		$this->assertStringNotContainsString( 'uploaded via', $text );
		$this->assertStringContainsString( 'Source: https://upload.wikimedia.org/x.jpg', $text );
	}

	public function testPageTextWithoutSource(): void {
		$text = ImageUploadHelper::pageText( 'Flameshot', 'logo' );
		$this->assertStringContainsString( 'Logo of Flameshot.', $text );
		$this->assertStringNotContainsString( 'uploaded via', $text );
		$this->assertStringNotContainsString( 'Source:', $text );
		$this->assertStringNotContainsString( 'License', $text );
		$this->assertStringNotContainsString( 'Attribution', $text );
	}

	public function testPageTextWithAttributionCarriesSemanticLicenseReference(): void {
		// A NEW upload's file page carries the license reference ([[Q42|label]],
		// NEVER a {{Q42}} template call) + the attribution block — the same
		// contract as Special:Upload (UploadHooks).
		$text = ImageUploadHelper::pageText(
			'Flameshot', 'logo', 'https://flameshot.org/logo.png',
			'Q42', 'CC BY-SA 4.0', 'E2E Logo Author', 'E2E license note'
		);
		$this->assertStringContainsString( 'Logo of Flameshot.', $text );
		$this->assertStringContainsString( '== License ==', $text );
		$this->assertStringContainsString( '[[Q42|CC BY-SA 4.0]]', $text );
		$this->assertStringNotContainsString( '{{Q42}}', $text );
		$this->assertStringContainsString( '== Attribution ==', $text );
		$this->assertStringContainsString( 'Author: E2E Logo Author', $text );
		$this->assertStringContainsString( 'Additional license information: E2E license note', $text );
		$this->assertStringContainsString( 'Source: https://flameshot.org/logo.png', $text );
	}

	public function testPageTextWithoutLicenseSkipsBothBlocks(): void {
		// Reuse-existing mode / no license: no License or Attribution blocks.
		$text = ImageUploadHelper::pageText( 'Flameshot', 'logo' );
		$this->assertStringNotContainsString( '==', $text );
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

	public function testModeFieldOffersThreeSourcesWithNoDefault(): void {
		$spec = ImageUploadHelper::modeField(
			'logo',
			'embeddablecontent-software-logo-mode',
			'embeddablecontent-software-logo-mode-file',
			'embeddablecontent-software-logo-mode-url',
			'embeddablecontent-upload-mode-existing'
		);
		$this->assertSame( 'radio', $spec['type'] );
		// No REAL source is pre-checked: the '' default matches only the
		// leading "Choose a source…" placeholder option (OOUI resets an
		// unmatched empty value to the FIRST option — the placeholder keeps
		// the group visibly unselected so the user picks the source).
		$this->assertSame( '', $spec['default'] );
		$this->assertSame(
			[
				'embeddablecontent-upload-mode-choose' => '',
				'embeddablecontent-software-logo-mode-file' => 'file',
				'embeddablecontent-software-logo-mode-url' => 'url',
				'embeddablecontent-upload-mode-existing' => 'existing',
			],
			$spec['options-messages']
		);
	}

	public function testFileFieldVisibleOnlyWhenModeFile(): void {
		$spec = ImageUploadHelper::fileField( 'portrait', 'embeddablecontent-person-portrait-file' );
		$this->assertSame( 'file', $spec['type'] );
		$this->assertSame(
			[ 'OR', [ '===', 'portraitInclude', '' ], [ '!==', 'portraitMode', 'file' ] ],
			$spec['hide-if']
		);
	}

	public function testUrlFieldVisibleOnlyWhenModeUrl(): void {
		$spec = ImageUploadHelper::urlField( 'logo', 'embeddablecontent-collective-logo-url' );
		$this->assertSame( 'url', $spec['type'] );
		$this->assertSame(
			[ 'OR', [ '===', 'logoInclude', '' ], [ '!==', 'logoMode', 'url' ] ],
			$spec['hide-if']
		);
		$this->assertStringContainsString( 'class="wb-uploadmeta"', $spec['help-raw'] );
	}

	public function testExistingFieldVisibleOnlyWhenModeExisting(): void {
		$spec = ImageUploadHelper::existingField( 'logo', 'embeddablecontent-upload-existing' );
		$this->assertSame( 'combobox', $spec['type'] );
		$this->assertSame( 'wb-file-combobox', $spec['cssclass'] );
		$this->assertSame(
			[ 'OR', [ '===', 'logoInclude', '' ], [ '!==', 'logoMode', 'existing' ] ],
			$spec['hide-if']
		);
		// fileselect.js renders the picked file's thumbnail into this slot.
		$this->assertStringContainsString( 'wb-file-preview', $spec['help'] );
	}

	/**
	 * The image facts (license / author / additional license information)
	 * hide in EXISTING mode: the reused file already carries them on its own
	 * page/item — the consumer entity only references the file via `image`.
	 */
	public function testLicenseFieldHiddenWhenModeExisting(): void {
		$spec = ImageUploadHelper::licenseField(
			'logo', 'embeddablecontent-collective-logo-license',
			'embeddablecontent-collective-logo-license-help',
			$this->configStub()
		);
		$this->assertSame(
			[ 'OR', [ '===', 'logoInclude', '' ], [ '!==', 'logoMode', 'existing' ] ],
			$spec['hide-if']
		);
	}

	public function testAuthorFieldHiddenWhenModeExisting(): void {
		$spec = ImageUploadHelper::authorField( 'portrait', 'embeddablecontent-person-portrait-author' );
		$this->assertSame(
			[ 'OR', [ '===', 'portraitInclude', '' ], [ '!==', 'portraitMode', 'existing' ] ],
			$spec['hide-if']
		);
	}

	public function testLicenseInfoFieldHiddenWhenModeExisting(): void {
		$spec = ImageUploadHelper::licenseInfoField( 'logo', 'embeddablecontent-collective-logo-license-info' );
		$this->assertSame(
			[ 'OR', [ '===', 'logoInclude', '' ], [ '!==', 'logoMode', 'existing' ] ],
			$spec['hide-if']
		);
	}

	/**
	 * Minimal valid config: licenseField() only reads licenseItems() (absent
	 * → the empty fallback), but the parent constructor asserts the base
	 * shape first.
	 *
	 * @return \EmbeddableContent\EmbeddableContentConfig
	 */
	private function configStub(): \EmbeddableContent\EmbeddableContentConfig {
		return new \EmbeddableContent\EmbeddableContentConfig( [
			'instanceOf' => 'P31',
			'classes' => [ 'quotation' => 'Q1', 'code' => 'Q2', 'math' => 'Q3' ],
			'payloadProperties' => [ 'quotation' => 'P1', 'code' => 'P2', 'math' => 'P3' ],
			'programmingLanguage' => 'P4',
			'provenance' => [ 'attributedTo' => 'P5', 'sourceUrl' => 'P6', 'source' => 'P7', 'date' => 'P8' ],
			'fallbackLanguages' => [ 'en' ],
		] );
	}
}
