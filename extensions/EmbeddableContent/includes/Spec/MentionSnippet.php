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
	 * The "Capitalise first letter" variant of a prefixed title: the first
	 * letter of the PAGE NAME (after the namespace prefix in a non-Main
	 * namespace) is uppercased. The namespace prefix is never touched.
	 *
	 * Only a lowercase letter is changed; digits, punctuation and an
	 * already-uppercase letter are returned unchanged. UTF-8 aware.
	 *
	 * MediaWiki's first-letter case-insensitivity (siteinfo `Case:
	 * first-letter`) makes the capitalised target resolve to the same page
	 * as the sentence-case mention — the variant is purely cosmetic, for
	 * use at the start of a sentence.
	 *
	 * @param string $prefixedText the page's prefixed title
	 * @param bool $isMainNamespace whether the page lives in the Main namespace
	 * @return string the prefixed title with the page name capitalised
	 */
	public static function capitalizePrefixedTitle(
		string $prefixedText,
		bool $isMainNamespace
	): string {
		$text = trim( $prefixedText );
		if ( $text === '' ) {
			return '';
		}
		$offset = 0;
		if ( !$isMainNamespace ) {
			$colon = strpos( $text, ':' );
			if ( $colon !== false ) {
				$offset = $colon + 1;
			}
		}
		$first = mb_substr( $text, $offset, 1, 'UTF-8' );
		if ( $first === '' || preg_match( '/^\p{Ll}/u', $first ) !== 1 ) {
			return $text;
		}
		return mb_substr( $text, 0, $offset, 'UTF-8' )
			. mb_strtoupper( $first, 'UTF-8' )
			. mb_substr( $text, $offset + 1, null, 'UTF-8' );
	}

	/**
	 * The full `[[…]]` snippet with the page name's first letter
	 * capitalised (see capitalizePrefixedTitle).
	 */
	public static function capitalizedFromPrefixedTitle(
		string $prefixedText,
		bool $isMainNamespace
	): string {
		$text = self::capitalizePrefixedTitle( $prefixedText, $isMainNamespace );
		return $text === '' ? '' : '[[' . $text . ']]';
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
