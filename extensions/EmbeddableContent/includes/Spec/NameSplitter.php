<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Mechanical full-name splitting for the Special:AddPerson search autofill
 * (issue #35): "every word except the last becomes the given name, the last
 * word the family name" — the same last-word-family convention the citation
 * engine's legacy label split uses. Pure, unit-testable (no MediaWiki or
 * Wikibase dependencies).
 *
 * @license GPL-2.0-or-later
 */
final class NameSplitter {

	/**
	 * @param string $name e.g. "Marie Curie", "Jean-Paul Charles Aymard Sartre"
	 * @return array<string,string> { givenName, familyName }
	 */
	public static function splitFullName( string $name ): array {
		$name = trim( $name );
		if ( $name === '' ) {
			return [ 'givenName' => '', 'familyName' => '' ];
		}
		if ( preg_match( '/^(.*?)\s+(\S+)$/u', $name, $m ) === 1 ) {
			return [ 'givenName' => $m[1], 'familyName' => $m[2] ];
		}
		// Single-word name: no "except last" part — it is the family name
		// (the user can correct the split on the form).
		return [ 'givenName' => '', 'familyName' => $name ];
	}
}
