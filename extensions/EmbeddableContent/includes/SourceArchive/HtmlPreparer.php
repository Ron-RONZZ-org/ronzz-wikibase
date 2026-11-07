<?php

declare( strict_types = 1 );

namespace EmbeddableContent\SourceArchive;

/**
 * Prepares fetched HTML for the HTML → wikitext conversion:
 *
 *  - removes chrome blocks that carry no page text (scripts, styles,
 *    iframes, SVG, comments, nav/footer/aside);
 *  - replaces every `<img>` with a placeholder token and records its
 *    `src` + `alt`, so the converter can emit the required
 *    "(Media content: view on original URL)" note plus the caption instead
 *    of a broken `[[File:…]]` embed.
 *
 * Pure PHP (no MediaWiki) and regex-based on purpose: the input is arbitrary
 * third-party HTML and the conversion is best-effort (the archived page
 * carries a "no warranty" banner). The image placeholder round-trip is the
 * part that must be exact, and it is exercised by the unit tests.
 *
 * @license GPL-2.0-or-later
 */
final class HtmlPreparer {

	/** Placeholder format; Pandoc passes it through as plain text. */
	public const IMAGE_TOKEN = 'ARCHIVEIMAGETOKEN%dEND';

	/** `<ref>…</ref>` placeholder: Pandoc's HTML reader drops unknown tags. */
	public const REF_TOKEN = 'ARCHIVEREFTOKEN%dEND';

	/** Blocks removed entirely (chrome, not page text). */
	private const DROP_BLOCKS = [
		'/<script\b[^>]*>.*?<\/script>/is',
		'/<style\b[^>]*>.*?<\/style>/is',
		'/<noscript\b[^>]*>.*?<\/noscript>/is',
		'/<iframe\b[^>]*>.*?<\/iframe>/is',
		'/<svg\b[^>]*>.*?<\/svg>/is',
		'/<nav\b[^>]*>.*?<\/nav>/is',
		'/<footer\b[^>]*>.*?<\/footer>/is',
		'/<aside\b[^>]*>.*?<\/aside>/is',
		'/<!--.*?-->/s',
		'/<link\b[^>]*>/i',
	];

	/**
	 * @return array{html:string, images:list<array{src:string, alt:string}>, refs:list<string>}
	 */
	public function prepare( string $html ): array {
		foreach ( self::DROP_BLOCKS as $pattern ) {
			$html = (string)preg_replace( $pattern, '', $html );
		}

		// Pandoc's HTML reader drops <ref>…</ref> (it keeps only the inner
		// text), so keep the tags alive through the converter.
		$refs = [];
		$html = (string)preg_replace_callback(
			'/<ref\b[^>]*>.*?<\/ref>|<ref\b[^>]*\/>/is',
			static function ( array $match ) use ( &$refs ): string {
				$refs[] = $match[0];
				return sprintf( self::REF_TOKEN, count( $refs ) - 1 );
			},
			$html
		);

		$images = [];
		$html = (string)preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( array $match ) use ( &$images ): string {
				$tag = $match[0];
				$images[] = [
					'src' => self::attribute( $tag, 'src' ),
					'alt' => self::attribute( $tag, 'alt' ),
				];
				return sprintf( self::IMAGE_TOKEN, count( $images ) - 1 );
			},
			$html
		);

		return [ 'html' => $html, 'images' => $images, 'refs' => $refs ];
	}

	/** Reads one attribute value from a start tag, entity-decoded. */
	private static function attribute( string $tag, string $name ): string {
		if ( !preg_match(
			'/\b' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i',
			$tag,
			$match
		) ) {
			return '';
		}
		// PCRE fills unmatched alternatives BEFORE the matched one with '',
		// so an empty group is not a match — pick the first non-empty group.
		$value = '';
		foreach ( [ 1, 2, 3 ] as $index ) {
			if ( isset( $match[$index] ) && $match[$index] !== '' ) {
				$value = $match[$index];
				break;
			}
		}
		return trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
