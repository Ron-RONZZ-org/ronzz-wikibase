<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Pure helper for the instance language-fallback decoration (Req 3 + Req 4):
 * appends the instance's configured fallback languages to a MediaWiki /
 * Wikibase term fallback chain.
 *
 * Wikibase builds every label/description fallback chain from the requested
 * (reader) language with `LanguageFallbackChainFactory`, whose chain for
 * `en` is `[en, mul]` — so an item labelled only in `fr` renders "No label
 * defined" on its Item: page and an empty label in the search results (the
 * Q2037/Q2086 reports). The instance's `fallbackLanguages` config
 * (`['fr','en','eo']`, the seed's column order) already drives
 * `action=entitysearch`; this helper lets the Wikibase factory use the same
 * order, so a request in `en` becomes `[en, fr, eo, mul]`.
 *
 * Pure — no MediaWiki runtime — so it is unit-testable standalone.
 *
 * @license GPL-2.0-or-later
 */
final class FallbackChain {

	/**
	 * Appends $extra to $fallbacks, de-duplicating and skipping the
	 * requested language (it is already at the head of the chain).
	 *
	 * @param string[] $fallbacks the base chain (already ordered)
	 * @param string $requested the language the chain is for
	 * @param string[] $extra the instance fallback languages
	 * @return string[] the chain with $extra appended
	 */
	public static function append( array $fallbacks, string $requested, array $extra ): array {
		$out = [];
		foreach ( $fallbacks as $code ) {
			$out[] = (string)$code;
		}
		foreach ( $extra as $code ) {
			$code = (string)$code;
			if ( $code === '' || $code === $requested || in_array( $code, $out, true ) ) {
				continue;
			}
			$out[] = $code;
		}
		return $out;
	}

}
