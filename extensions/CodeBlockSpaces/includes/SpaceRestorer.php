<?php

declare( strict_types = 1 );

namespace CodeBlockSpaces;

/**
 * Restores literal spaces inside `<pre>` / `<code>` HTML.
 *
 * MediaWiki core's *French space armoring* — `Sanitizer::armorFrenchSpaces()`,
 * applied unconditionally by `Parser::internalParse()` — rewrites the space
 * before `! ? : ; % » ›` (and after `« ‹`) into `&#160;` (U+00A0). That is
 * correct for prose, but wrong for literal code: the non-breaking space
 * survives into the rendered `<pre>`/`<code>` text, so it is what the
 * SyntaxHighlight copy button puts on the clipboard (it copies
 * `textContent`), and it silently corrupts pasted commands — a Vim `\=`
 * replacement expression aborts with `E488: Trailing characters` and the
 * match is replaced with an empty string (the katex content disappears).
 *
 * This class reverts only that one substitution, and only inside `<pre>` and
 * `<code>` elements, so prose armoring is untouched.
 *
 * @license GPL-2.0-or-later
 */
final class SpaceRestorer {

	/** The armored forms core emits (default `&#160;`, plus the named form). */
	private const ARMORED_SPACES = [ '&#160;', '&nbsp;' ];

	/**
	 * Replace armored spaces with plain spaces inside preformatted/code
	 * elements. A no-op when the HTML carries no armored space.
	 */
	public static function restore( string $html ): string {
		if ( $html === ''
			|| ( !str_contains( $html, '&#160;' ) && !str_contains( $html, '&nbsp;' ) )
		) {
			return $html;
		}

		// The closing tag is tied to the opening one (\2) so a <code> nested
		// inside a <pre> is consumed by the outer match (and fixed there).
		$restored = preg_replace_callback(
			'#(<(pre|code)\b[^>]*>)(.*?)(</\2\s*>)#is',
			static function ( array $match ): string {
				return $match[1] . str_replace( self::ARMORED_SPACES, ' ', $match[3] ) . $match[4];
			},
			$html
		);

		return $restored ?? $html;
	}
}
