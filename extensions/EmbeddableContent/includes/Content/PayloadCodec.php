<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

/**
 * Lossless escape/unescape codec for content-item payloads (issue #6 §8
 * escalation, option A: escape-at-rest, decode-at-render).
 *
 * Wikibase string and monolingualtext values reject vertical whitespace and
 * tabs (ValidatorBuilders::getCommonStringValidators →
 * wikibase-validator-illegal-string-chars), so a logically multi-line
 * payload cannot be stored as-is. The codec encodes carriage returns,
 * newlines, tabs and backslashes as backslash sequences at rest; the content
 * renderers and the {{#content:}} decoder function decode on output.
 *
 * Backslashes are escaped first, so a literal "\n" in user content survives
 * a round trip:
 *
 *   escape( "a\nb" )   → "a\\nb"    (newline → two-char sequence)
 *   escape( "a\\nb" )  → "a\\\\nb"  (literal backslash → "\\")
 *   decode( "a\\nb" )  → "a\nb"     (real newline)
 *   decode( "a\\\\nb" ) → "a\\nb"   (literal backslash-n, unchanged)
 *
 * The two stored forms decode to distinct values; the wiki's validators see
 * no vertical whitespace at rest.
 *
 * @license GPL-2.0-or-later
 */
final class PayloadCodec {

	/**
	 * Encodes a payload for storage: backslash first, then the whitespace
	 * the wiki's string values reject.
	 */
	public static function escape( string $payload ): string {
		$escaped = str_replace( '\\', '\\\\', $payload );
		$escaped = str_replace( "\r", '\\r', $escaped );
		$escaped = str_replace( "\n", '\\n', $escaped );
		$escaped = str_replace( "\t", '\\t', $escaped );
		return $escaped;
	}

	/**
	 * Decodes a stored payload back to its logical content. The alternation
	 * is built with preg_quote so the backslash escaping stays explicit, and
	 * the `\\` sentinel (escaped backslash) comes first, so a stored escaped
	 * backslash is never re-read as the start of an escape sequence.
	 */
	public static function decode( string $payload ): string {
		$map = [
			'\\\\' => '\\', // stored \\ → literal backslash
			'\\r' => "\r",
			'\\n' => "\n",
			'\\t' => "\t",
		];
		$pattern = '/' . implode( '|', array_map( 'preg_quote', array_keys( $map ) ) ) . '/';
		return preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( $map ): string {
				return $map[$m[0]];
			},
			$payload
		) ?? $payload;
	}
}
