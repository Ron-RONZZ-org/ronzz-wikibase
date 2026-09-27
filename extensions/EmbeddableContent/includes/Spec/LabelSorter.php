<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Locale-aware label sorting for HTMLForm option maps.
 *
 * The Add* pickers render `[label => value]` option maps in config/manifest
 * order. Contributors expect alphabetical order in their own language, and a
 * byte sort is wrong for it: French "Législation" belongs under L, not after
 * Z. ICU's Collator (ext-intl, a MediaWiki hard requirement) compares
 * locale-aware; the sort falls back to `strcasecmp` when intl is somehow
 * absent and tie-breaks equal labels by their input position (PHP's sort is
 * not stable).
 *
 * Pure PHP — no MediaWiki dependency; unit-testable standalone.
 *
 * @license GPL-2.0-or-later
 */
final class LabelSorter {

	/**
	 * @param array<string,mixed> $labelToValue label => value (the HTMLForm
	 *  'options' shape)
	 * @param string $language BCP-47 language code for the collation
	 * @return array<string,mixed> the same map, sorted by label
	 */
	public static function sortByLabel( array $labelToValue, string $language = 'en' ): array {
		if ( count( $labelToValue ) < 2 ) {
			return $labelToValue;
		}
		$collator = class_exists( \Collator::class ) ? new \Collator( $language ) : null;

		$labels = array_keys( $labelToValue );
		$position = array_flip( $labels );
		uksort(
			$labelToValue,
			static function ( $a, $b ) use ( $collator, $position ): int {
				$a = (string)$a;
				$b = (string)$b;
				if ( $a === $b ) {
					return 0;
				}
				$cmp = $collator !== null
					? $collator->compare( $a, $b )
					: strcasecmp( $a, $b );
				return $cmp !== 0 ? $cmp : ( $position[$a] <=> $position[$b] );
			}
		);
		return $labelToValue;
	}
}
