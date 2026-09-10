<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use LanguageBar\LanguageBar;
use PHPUnit\Framework\TestCase;

/**
 * Pure-PHP tests for the LanguageBar builder: the bar markup contract, the
 * namespace allow-list and the translation-subpage detection.
 *
 * The MediaWiki-bound paths (the OutputPageBeforeHTML injection and the
 * `{{#languagebar:}}` parser function) are covered by
 * tests/e2e/run_languagebar_e2e.py — this suite has no MediaWiki runtime.
 *
 * @covers \LanguageBar\LanguageBar
 * @license GPL-2.0-or-later
 */
final class LanguageBarTest extends TestCase {

	public function testComposeRendersTheBarContract(): void {
		$html = LanguageBar::compose( 'Languages', [ '<a>English</a>', '<a>français</a>' ] );

		$this->assertStringContainsString( 'class="languages-bar"', $html );
		$this->assertStringContainsString(
			'style="border:1px solid #a2a9b1;background:#f8f9fa;padding:0.3em 1em;margin:0 0 1em;"',
			$html
		);
		// The historical template renders its wikitext line paragraph-wrapped.
		$this->assertStringContainsString( '<p><b>Languages:</b> ', $html );
		$this->assertStringContainsString( '<a>English</a> · <a>français</a>', $html );
		$this->assertStringEndsWith( '</p></div>', $html );
	}

	public function testComposeEscapesTheLabel(): void {
		$html = LanguageBar::compose( 'A<"b', [] );
		$this->assertStringContainsString( '<b>A&lt;&quot;b:</b>', $html );
	}

	public function testTranslationLanguagesAreFrAndEo(): void {
		$this->assertSame(
			[ 'fr' => 'français', 'eo' => 'Esperanto' ],
			LanguageBar::TRANSLATION_LANGUAGES
		);
	}

	public function testIsEnabledNamespace(): void {
		$allowed = [ 0, 12, 2010 ];

		$this->assertTrue( LanguageBar::isEnabledNamespace( 0, $allowed ) );
		$this->assertTrue( LanguageBar::isEnabledNamespace( 12, $allowed ) );
		$this->assertTrue( LanguageBar::isEnabledNamespace( 2010, $allowed ) );
		// Entity, Template, Forum and the gated ops namespaces are not enabled.
		$this->assertFalse( LanguageBar::isEnabledNamespace( 110, $allowed ) );
		$this->assertFalse( LanguageBar::isEnabledNamespace( 10, $allowed ) );
		$this->assertFalse( LanguageBar::isEnabledNamespace( 120, $allowed ) );
		$this->assertFalse( LanguageBar::isEnabledNamespace( 2006, $allowed ) );
		// Strict comparison: a string id must not match an int list.
		$this->assertFalse( LanguageBar::isEnabledNamespace( 0, [ '0' ] ) );
	}

	/**
	 * @dataProvider provideSubpageNames
	 */
	public function testIsTranslationSubpageName( string $pageName, bool $expected ): void {
		$this->assertSame( $expected, LanguageBar::isTranslationSubpageName( $pageName ) );
	}

	public static function provideSubpageNames(): array {
		return [
			'fr copy' => [ 'Help:Contributing/code/fr', true ],
			'eo copy' => [ 'Person:Ada Lovelace/eo', true ],
			'english original' => [ 'Help:Contributing/code', false ],
			'another language' => [ 'Help:Contributing/code/es', false ],
			'language-like suffix' => [ 'Help:Contributing/code/francais', false ],
			'bare fr title' => [ 'fr', false ],
		];
	}
}
