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

	/** A quotation expands to its own wikitext, unchanged. */
	public static function quotation( string $wikitext ): string {
		return $wikitext;
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
