<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Builds the "Copy internal mention" wikitext snippet for an ordinary
 * content page — the one contract shared by the server (Hooks) and the
 * resource module that renders the button.
 *
 * The snippet is a plain internal link `[[Page name]]`. In the MAIN
 * namespace the first letter is lowercased (sentence case) UNLESS the title
 * is a proper name — "every word starts with an uppercase letter"
 * ("Albert Einstein", "Main Page", a single capitalised word). Titles in
 * every other namespace are left exactly as the page title reads
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
	 * @param string $prefixedText the page's prefixed title (e.g. "Main Page",
	 *  "Help:Contributing")
	 * @param bool $isMainNamespace whether the page lives in the Main namespace
	 * @return string the `[[…]]` snippet, or '' for an empty title
	 */
	public static function fromPrefixedTitle( string $prefixedText, bool $isMainNamespace ): string {
		$text = trim( $prefixedText );
		if ( $text === '' ) {
			return '';
		}
		if ( $isMainNamespace && !self::isProperName( $text ) ) {
			$text = self::lowercaseFirstLetter( $text );
		}
		return '[[' . $text . ']]';
	}

	/**
	 * True when every whitespace-separated word begins with an uppercase
	 * letter — the proper-name signal that suppresses the lowercasing.
	 */
	private static function isProperName( string $text ): bool {
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( $words === false || $words === [] ) {
			return false;
		}
		foreach ( $words as $word ) {
			if ( preg_match( '/^\p{Lu}/u', $word ) !== 1 ) {
				return false;
			}
		}
		return true;
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
