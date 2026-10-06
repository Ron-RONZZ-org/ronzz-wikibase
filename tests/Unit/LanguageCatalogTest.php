<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Spec\LanguageCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ISO 639 language catalog backing the AddSource language
 * comboboxes.
 *
 * @license GPL-2.0-or-later
 */
final class LanguageCatalogTest extends TestCase {

	private const FIXTURE = "code,english_name,part\n"
		. "en,English,639-1\n"
		. "eng,English,639-2\n"
		. "fr,French,639-1\n"
		. "gsw,Swiss German; Alemannic,639-2\n";

	public function testLoadParsesCodeAndEnglishName(): void {
		$path = tempnam( sys_get_temp_dir(), 'iso' );
		file_put_contents( $path, self::FIXTURE );
		try {
			$names = LanguageCatalog::load( $path );
		} finally {
			unlink( $path );
		}
		$this->assertSame( 'English', $names['en'] );
		$this->assertSame( 'French', $names['fr'] );
		$this->assertSame( 'Swiss German; Alemannic', $names['gsw'] );
	}

	public function testLoadReturnsEmptyForMissingFile(): void {
		$this->assertSame( [], LanguageCatalog::load( '/nonexistent/iso.csv' ) );
	}

	public function testOptionsLabelCarriesCodeAndEnglishName(): void {
		$options = LanguageCatalog::options();
		$this->assertArrayHasKey( 'en' . LanguageCatalog::LABEL_SEPARATOR . 'English', $options );
		$this->assertSame( 'en', $options[ 'en' . LanguageCatalog::LABEL_SEPARATOR . 'English' ] );
		$this->assertSame( 'fr', $options[ 'fr' . LanguageCatalog::LABEL_SEPARATOR . 'French' ] );
	}

	public function testIsKnown(): void {
		$this->assertTrue( LanguageCatalog::isKnown( 'en' ) );
		$this->assertTrue( LanguageCatalog::isKnown( '  EN ' ) );
		$this->assertFalse( LanguageCatalog::isKnown( 'zzzz' ) );
	}

	public function testIsCode(): void {
		$this->assertTrue( LanguageCatalog::isCode( 'en' ) );
		$this->assertTrue( LanguageCatalog::isCode( 'eng' ) );
		$this->assertTrue( LanguageCatalog::isCode( 'zh-hant' ) );
		$this->assertFalse( LanguageCatalog::isCode( 'not a code' ) );
		$this->assertFalse( LanguageCatalog::isCode( '' ) );
	}
}
