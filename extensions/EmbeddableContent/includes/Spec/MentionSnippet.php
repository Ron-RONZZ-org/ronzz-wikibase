<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Builds the "Copy internal mention" wikitext snippet for an ordinary
 * content page — the one contract shared by the server (Hooks) and the
 * resource module that renders the button.
 *
 * The snippet is a plain internal link `[[Page name]]`. In the MAIN
 * namespace the first letter is lowercased (sentence case) UNLESS the page
 * is in Category:Proper name — the explicit opt-out that replaced the old
 * "every word starts with an uppercase letter" heuristic (which kept every
 * capitalised single word, e.g. "Permutation"). Titles in every other
 * namespace are left exactly as the page title reads
 * (`Help:Contributing`, never `help:Contributing`).
 *
 * MediaWiki's capital-links rule makes the first letter of a Main-namespace
 * link case-insensitive, so the lowercased form resolves identically — the
 * convention is purely cosmetic. Pure PHP (mbstring only), unit-tested.
 *
 * @license GPL-2.0-or-later
 */
final class MentionSnippet {

	/**
	 * The category that keeps a Main-namespace title's capital initial
	 * (the page must be a member; the category page itself is hidden).
	 */
	public const PROPER_NAME_CATEGORY = 'Proper name';

	/**
	 * @param string $prefixedText the page's prefixed title (e.g. "Main Page",
	 *  "Help:Contributing")
	 * @param bool $isMainNamespace whether the page lives in the Main namespace
	 * @param bool $isProperName whether the page is in Category:Proper name
	 * @return string the `[[…]]` snippet, or '' for an empty title
	 */
	public static function fromPrefixedTitle(
		string $prefixedText,
		bool $isMainNamespace,
		bool $isProperName = false
	): string {
		$text = trim( $prefixedText );
		if ( $text === '' ) {
			return '';
		}
		if ( $isMainNamespace && !$isProperName ) {
			$text = self::lowercaseFirstLetter( $text );
		}
		return '[[' . $text . ']]';
	}

	/**
	 * Lowercases the first character when it is an uppercase letter; any
	 * other first character (lowercase, digit, punctuation) is returned
	 * unchanged. UTF-8 aware (mbstring).
	 */
	private static function lowercaseFirstLetter( string $text ): string {
		if ( preg_match( '/^\p{Lu}/u', $text ) !== 1 ) {
			return $text;
		}
		$first = mb_substr( $text, 0, 1, 'UTF-8' );
		return mb_strtolower( $first, 'UTF-8' ) . mb_substr( $text, 1, null, 'UTF-8' );
	}

}
