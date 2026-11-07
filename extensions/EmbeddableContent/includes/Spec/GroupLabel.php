<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Builds the default label for a group-of-humans item from its members:
 * "Name1, Name2, Name3". If the next name would overflow the label limit,
 * " et al." is appended instead of truncating a name mid-word.
 *
 * Pure (no MediaWiki/Wikibase runtime) so the limit/et-al behaviour is
 * unit-testable. The caller loads the member display labels (full names)
 * and passes them in order.
 *
 * @license GPL-2.0-or-later
 */
final class GroupLabel {

	/** The item-label limit (the Add* forms' label maxlength). */
	public const DEFAULT_MAX_LENGTH = 250;

	/** The overflow marker. */
	public const ET_AL = 'et al.';

	/**
	 * @param string[] $memberLabels display names, in member order
	 */
	public static function fromMembers(
		array $memberLabels,
		int $maxLength = self::DEFAULT_MAX_LENGTH
	): string {
		$names = [];
		foreach ( $memberLabels as $label ) {
			$label = trim( (string)$label );
			if ( $label !== '' ) {
				$names[] = $label;
			}
		}
		if ( $names === [] ) {
			return '';
		}
		$maxLength = max( 1, $maxLength );
		$suffix = ' ' . self::ET_AL;
		$reserved = mb_strlen( $suffix );
		$label = '';
		foreach ( $names as $index => $name ) {
			$candidate = $label === '' ? $name : $label . ', ' . $name;
			$remaining = count( $names ) - $index - 1;
			$mustReserve = $remaining > 0
				&& mb_strlen( $candidate ) + $reserved > $maxLength;
			if ( mb_strlen( $candidate ) <= $maxLength && !$mustReserve ) {
				$label = $candidate;
				continue;
			}
			// This name (or a later one) cannot fit: use the overflow marker
			// on the names accepted so far.
			if ( $label !== '' && mb_strlen( $label . $suffix ) <= $maxLength ) {
				return $label . $suffix;
			}
			// The first name itself is too long for the marker form: hard
			// truncate it to the limit (no other option).
			if ( $label === '' ) {
				return mb_substr( $name, 0, $maxLength );
			}
			return mb_substr( $label, 0, $maxLength );
		}
		if ( mb_strlen( $label ) > $maxLength ) {
			return mb_substr( $label, 0, $maxLength );
		}
		return $label;
	}

}
