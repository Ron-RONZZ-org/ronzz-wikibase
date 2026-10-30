<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the house CSS against hard-coded palette values that do not adapt to
 * the Vector 2022 night theme.
 *
 * The instance's own surfaces (toolbars, upload previews, autofill banners,
 * math previews, source tables) must use Codex theme tokens with light-mode
 * fallbacks — `var(--color-base, #202122)` and friends. A fixed `#202122`
 * text colour on a surface whose background the night theme remaps to dark
 * renders invisible (the AddPerson place-of-birth confirmation report); the
 * same class of bug keeps recurring, so this test fails on any new colour
 * literal in the audited files.
 *
 * Deliberately excluded: vendored assets (KaTeX, highlight.js, the OOUI
 * styles) and the GeoGebra player origin's own white page
 * (`resources/player/player.css`).
 *
 * @license GPL-2.0-or-later
 */
final class ThemeTokensTest extends TestCase {

	/** CSS files whose colours must come from theme tokens. */
	private const AUDITED_FILES = [
		'extensions/EmbeddableContent/resources/embed.css',
		'extensions/EmbeddableContent/resources/gadget.css',
		'extensions/EmbeddableContent/resources/uploadmeta.css',
		'extensions/EmbeddableContent/resources/uploadform.css',
		'extensions/EmbeddableContent/resources/sitelinktab.css',
		'extensions/EmbeddableContent/resources/addsource-labelpreview.css',
		'extensions/EmbeddableContent/resources/userpagetitle.css',
		'extensions/CSVTable/resources/ext.csvTable.css',
		'extensions/GeoGebra/resources/ext.geogebra.css',
		'extensions/LanguageBar/resources/languages-bar.css',
		'extensions/Pyodide4MediaWiki/resources/ext.pyodide4mediawiki.css',
	];

	/** Properties whose value carries a colour (or a colour shorthand). */
	private const COLOUR_PROPERTIES = [
		'color', 'background', 'background-color',
		'border', 'border-color', 'border-top', 'border-right', 'border-bottom', 'border-left',
		'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
		'border-inline-start', 'border-inline-end', 'border-block-start', 'border-block-end',
		'outline', 'outline-color', 'box-shadow', 'text-shadow', 'fill', 'stroke',
	];

	/** Values that legitimately carry no colour literal. */
	private const COLOUR_LITERAL = '/(?:#[0-9a-fA-F]{3,8}\b|\brgba?\s*\(|\bhsla?\s*\()/';

	public function testHouseCssUsesThemeTokens(): void {
		$violations = [];
		foreach ( self::AUDITED_FILES as $relative ) {
			$css = file_get_contents( __DIR__ . '/../../' . $relative );
			$this->assertIsString( $css, "$relative is missing" );
			// Comments can mention colours; the declarations are what matter.
			$css = preg_replace( '#/\*.*?\*/#s', '', $css ) ?? $css;
			if ( preg_match_all( '/([a-z-]+)\s*:\s*([^;{}]+);/i', $css, $matches, PREG_SET_ORDER ) === false ) {
				continue;
			}
			foreach ( $matches as $match ) {
				$property = strtolower( $match[1] );
				$value = trim( $match[2] );
				if ( !in_array( $property, self::COLOUR_PROPERTIES, true ) ) {
					continue;
				}
				if ( preg_match( self::COLOUR_LITERAL, $value ) !== 1 ) {
					continue;
				}
				if ( str_contains( $value, 'var(' ) ) {
					continue;
				}
				$violations[] = "{$relative}: {$property}: {$value}";
			}
		}
		$this->assertSame(
			[],
			$violations,
			"Hard-coded colours in house CSS (use a Codex token with a fallback):\n"
				. implode( "\n", $violations )
		);
	}

	public function testAutofillConfirmTextUsesTheBaseToken(): void {
		$css = file_get_contents(
			__DIR__ . '/../../extensions/EmbeddableContent/resources/uploadmeta.css'
		);
		$this->assertIsString( $css );
		$this->assertMatchesRegularExpression(
			'/\.wb-entity-confirm-line\s*\{[^}]*\bcolor:\s*var\(--color-base\b/s',
			$css,
			'the autofill-confirm text must use var(--color-base, …) so it follows the night theme'
		);
	}
}
