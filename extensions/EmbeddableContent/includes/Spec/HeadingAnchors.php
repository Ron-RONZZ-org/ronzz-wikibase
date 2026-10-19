<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Injects a stable, linkable `<span id>` into headings whose generated id
 * cannot be used in a wikitext fragment (math or the illegal characters
 * `# < > [ ] | { }`), so `[[Page#anchor]]` links copied from the per-section
 * controls resolve in the initial HTML — no JavaScript required.
 *
 * MediaWiki already emits an empty legacy `<span id="…">` right after the
 * heading id when the id needs the legacy fragment encoding (`$`, `\`, `{`,
 * `}`, …). An explicit `{{anchor|…}}` span follows it. This pass only acts
 * when there is no explicit anchor: it computes the automatic anchor
 * (`Spec\SectionAnchor`) from the heading id and inserts the span AFTER the
 * legacy one, so the existing section-control rule ("last empty span wins")
 * picks the explicit anchor when present and the automatic one otherwise.
 *
 * The heading's own id is never changed, so previously working links keep
 * working; the automatic span is an additional alias. Ids are unique
 * case-insensitively (MediaWiki's T12721 rule), so a collision with any id
 * already on the page gets `_2`, `_3`, … .
 *
 * Pure HTML transform (no MediaWiki runtime), unit-tested.
 *
 * @license GPL-2.0-or-later
 */
final class HeadingAnchors {

	/** Marks the injected span so the section-control JavaScript can tell it apart. */
	public const AUTO_ANCHOR_CLASS = 'wb-section-auto-anchor';

	/** A balanced `<h1>`–`<h6>` element (MediaWiki's heading markup). */
	private const HEADING_PATTERN = '/<h([1-6])\b([^>]*)>(.*?)<\/h\1>/is';

	/** An empty `<span id="…"></span>` — the legacy and `{{anchor}}` shape. */
	private const EMPTY_SPAN_PATTERN = '/<span\b[^>]*\bid="([^"]*)"[^>]*>\s*<\/span>/i';

	/**
	 * @param string $html the parsed page HTML (ParserAfterTidy)
	 * @return string the HTML with the automatic anchors added
	 */
	public static function inject( string $html ): string {
		if ( stripos( $html, '<h' ) === false ) {
			return $html;
		}
		$usedIds = self::collectIds( $html );
		$result = preg_replace_callback(
			self::HEADING_PATTERN,
			static function ( array $match ) use ( &$usedIds ): string {
				return self::injectIntoHeading( $match, $usedIds );
			},
			$html
		);
		return $result ?? $html;
	}

	/**
	 * @param array $match a HEADING_PATTERN match: [full, level, attributes, content]
	 * @param array<string,bool> &$usedIds lowercased ids already on the page
	 */
	private static function injectIntoHeading( array $match, array &$usedIds ): string {
		$level = $match[1];
		$attributes = $match[2];
		$content = $match[3];
		if ( preg_match( '/\bid="([^"]*)"/i', $attributes, $idMatch ) !== 1 ) {
			return $match[0];
		}
		$headingId = self::decode( $idMatch[1] );
		$anchor = SectionAnchor::fromHeadingId( $headingId );
		if ( $anchor === '' || $anchor === $headingId ) {
			return $match[0];
		}

		$spans = self::emptySpans( $content );
		$legacyId = self::legacyId( $headingId );
		$hasLegacySpan = $spans !== [] && $spans[0] === $legacyId;
		$explicitSpans = count( $spans ) - ( $hasLegacySpan ? 1 : 0 );
		if ( $explicitSpans > 0 ) {
			// A contributor-chosen {{anchor}} wins over the automatic alias.
			return $match[0];
		}

		$uniqueAnchor = self::unique( $anchor, $usedIds );
		$usedIds[ strtolower( $uniqueAnchor ) ] = true;
		$span = '<span class="' . self::AUTO_ANCHOR_CLASS . '" id="'
			. htmlspecialchars( $uniqueAnchor, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"></span>';

		if ( $hasLegacySpan ) {
			// Insert right after the legacy span, before the heading text.
			$content = self::insertAfterFirstEmptySpan( $content, $span );
		} else {
			$content = $span . $content;
		}
		return '<h' . $level . $attributes . '>' . $content . '</h' . $level . '>';
	}

	/**
	 * @return string[] the decoded ids of every empty `<span id>` in the heading
	 */
	private static function emptySpans( string $content ): array {
		if ( preg_match_all( self::EMPTY_SPAN_PATTERN, $content, $matches ) === false ) {
			return [];
		}
		return array_map( [ self::class, 'decode' ], $matches[1] );
	}

	/** Inserts `$span` immediately after the first empty `<span id>` (the legacy one). */
	private static function insertAfterFirstEmptySpan( string $content, string $span ): string {
		$result = preg_replace_callback(
			'/(<span\b[^>]*\bid="[^"]*"[^>]*>\s*<\/span>)/i',
			static function ( array $match ) use ( $span ): string {
				return $match[1] . $span;
			},
			$content,
			1
		);
		return $result ?? $content;
	}

	/**
	 * Every id already present on the page, lowercased for MediaWiki's
	 * case-insensitive id uniqueness.
	 *
	 * @return array<string,bool>
	 */
	private static function collectIds( string $html ): array {
		$used = [];
		if ( preg_match_all( '/\bid="([^"]*)"/i', $html, $matches ) === false ) {
			return $used;
		}
		foreach ( $matches[1] as $id ) {
			$used[ strtolower( self::decode( $id ) ) ] = true;
		}
		return $used;
	}

	/**
	 * The legacy fragment encoding MediaWiki puts in the empty span before
	 * the heading text (`$` → `.24`, `\` → `.5C`, …). An explicit anchor is
	 * the other kind of empty span; comparing against this tells the two
	 * apart without the MediaWiki runtime.
	 */
	private static function legacyId( string $headingId ): string {
		$encoded = urlencode( str_replace( ' ', '_', $headingId ) );
		return strtr( $encoded, [ '%3A' => ':', '%' => '.' ] );
	}

	/**
	 * @param string $anchor the desired anchor
	 * @param array<string,bool> $usedIds lowercased ids already on the page
	 */
	private static function unique( string $anchor, array $usedIds ): string {
		if ( !isset( $usedIds[ strtolower( $anchor ) ] ) ) {
			return $anchor;
		}
		for ( $suffix = 2; ; $suffix++ ) {
			$candidate = $anchor . '_' . $suffix;
			if ( !isset( $usedIds[ strtolower( $candidate ) ] ) ) {
				return $candidate;
			}
		}
	}

	private static function decode( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

}
