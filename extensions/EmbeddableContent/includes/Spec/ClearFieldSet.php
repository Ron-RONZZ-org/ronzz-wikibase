<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * The blank-field selection shared by the Update* forms: a form field whose
 * submitted value is blank (after trimming) is an explicit clear. Pure so the
 * mapping is unit-tested without an HTMLForm/MediaWiki runtime.
 *
 * @license GPL-2.0-or-later
 */
final class ClearFieldSet {

	/**
	 * @param array<string,mixed> $data submitted form values (field name => value)
	 * @param string[] $fields the fact fields the form rendered
	 * @return string[] the blanked fields, in the input order
	 */
	public static function blank( array $data, array $fields ): array {
		$out = [];
		foreach ( $fields as $field ) {
			if ( trim( (string)( $data[$field] ?? '' ) ) === '' ) {
				$out[] = (string)$field;
			}
		}
		return $out;
	}

}
