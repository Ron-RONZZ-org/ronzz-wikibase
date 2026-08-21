<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Pure splitting/normalization of multi-valued entity-field input, so the
 * Special pages can accept several entities per fact (issue follow-up:
 * FOSS developer/operating-system/license, math 'describes', code
 * 'implementation of').
 *
 * The input is a comma/semicolon/whitespace-separated list of item ids
 * ("Q5, Q179" or "q5; Q179"). This class only SPLITS and NORMALIZES
 * (trim, upper-case, dedupe) — validation against the Wikibase entity id
 * grammar is the caller's job (it needs the Wikibase services, which this
 * pure class deliberately does not touch, keeping it unit-testable).
 *
 * @license GPL-2.0-or-later
 */
final class ItemIdList {

	/**
	 * @param string $input raw field value, e.g. "Q5, Q179"
	 * @return string[] normalized, deduped candidate ids (unvalidated),
	 *                  e.g. [ "Q5", "Q179" ]
	 */
	public static function split( string $input ): array {
		$out = [];
		foreach ( preg_split( '/[,;\s]+/', trim( $input ) ) as $part ) {
			if ( $part === '' ) {
				continue;
			}
			// Item ids are case-insensitive on input (q5 → Q5); Wikibase
			// serializes them upper-case.
			$normalized = strtoupper( $part );
			$out[$normalized] = $normalized;
		}
		return array_values( $out );
	}
}
