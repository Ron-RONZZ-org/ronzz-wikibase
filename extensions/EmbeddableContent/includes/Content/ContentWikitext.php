<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

/**
 * Pure wikitext assembly for the `{{#content:}}` parser function.
 *
 * `{{#content:}}` expands a content item into REGULAR WIKITEXT and lets the
 * consumer page decide the formatting — it never adds the embed chrome
 * (`.wb-embed` border/background). Quotations expand to their wikitext;
 * math expands to a display `$$…$$` span (rendered by the instance's
 * SimpleMathJax like every other formula); code expands to a stock
 * `<syntaxhighlight>` block. This class holds the MW-free string assembly so
 * it can be unit-tested without a MediaWiki runtime.
 *
 * @license GPL-2.0-or-later
 */
final class ContentWikitext {

	/** Display-math delimiter (the instance's $wgSmjDisplayMath). */
	public const MATH_DELIMITER = '$$';

	/**
	 * A quotation expands to its own wikitext, optionally followed by the
	 * attribution line on its own paragraph:
	 *
	 *   ''Beloved''
	 *
	 *   -Toni Morrison, ''Beloved''
	 *
	 * The caller assembles the attribution string (see quotationAttribution)
	 * — this method only places it below the content.
	 */
	public static function quotation( string $wikitext, string $attribution = '' ): string {
		$attribution = trim( $attribution );
		if ( $attribution === '' ) {
			return $wikitext;
		}
		return $wikitext . "\n\n" . $attribution;
	}

	/**
	 * The translation blocks rendered below a quotation's original text, one
	 * block per requested language:
	 *
	 *   '''fr translation:'''
	 *
	 *   Le texte traduit.
	 *
	 * A block whose `text` is null (no translation claim for that language)
	 * renders `notFound` in place of the text — the "{code} translation not
	 * found" fallback. Blocks are separated by a blank line; empty input
	 * yields an empty string. The header/notFound strings are localized by
	 * the caller (this class stays MediaWiki-free).
	 *
	 * @param array<int,array{header:string,text:?string,notFound:string}> $translations
	 */
	public static function quotationTranslations( array $translations ): string {
		$blocks = [];
		foreach ( $translations as $translation ) {
			$body = $translation['text'] !== null
				? (string)$translation['text']
				: (string)$translation['notFound'];
			$blocks[] = "'''" . $translation['header'] . "'''\n\n" . $body;
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * The attribution line of a quotation: `-author, ''source''` from the
	 * already-resolved display strings. Authors render plain, sources carry
	 * their own italic/link markup (the caller resolves them), so this is a
	 * pure join: the two lists are comma-separated, prefixed with a dash.
	 * Empty when neither list has an entry; a missing part is omitted.
	 *
	 * @param string[] $authors plain author display names
	 * @param string[] $sources source display strings (may carry markup)
	 */
	public static function quotationAttribution( array $authors, array $sources ): string {
		$parts = [];
		foreach ( [ $authors, $sources ] as $list ) {
			foreach ( $list as $value ) {
				$value = trim( (string)$value );
				if ( $value !== '' ) {
					$parts[] = $value;
				}
			}
		}
		if ( $parts === [] ) {
			return '';
		}
		return '-' . implode( ', ', $parts );
	}

	/**
	 * A math item expands to a display-math span, optionally followed by the
	 * accompanying note as wikitext (separated by a blank line so it renders
	 * as its own paragraph).
	 */
	public static function math( string $latex, string $note = '' ): string {
		$out = self::MATH_DELIMITER . $latex . self::MATH_DELIMITER;
		$note = trim( $note );
		if ( $note !== '' ) {
			$out .= "\n\n" . $note;
		}
		return $out;
	}

	/**
	 * A code snippet expands to the stock SyntaxHighlight tag. A literal
	 * closing tag inside the code would truncate the block (the parser stops
	 * at the first `</syntaxhighlight>`); fall back to an escaped <pre>
	 * then, so no content is silently lost.
	 */
	public static function code( string $code, string $lexer ): string {
		if ( stripos( $code, '</syntaxhighlight' ) !== false ) {
			return '<pre>' . htmlspecialchars( $code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</pre>';
		}
		$lexer = trim( $lexer ) !== '' ? trim( $lexer ) : 'text';
		return '<syntaxhighlight lang="' . htmlspecialchars( $lexer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">'
			. $code . '</syntaxhighlight>';
	}
}
