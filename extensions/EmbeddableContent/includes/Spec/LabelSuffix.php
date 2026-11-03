<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * The "allow duplicate" label suffix: the next free ` (N)` in the sequence
 * ` (2)`, ` (3)`, ` (4)`, … An existing numeric suffix is incremented instead
 * of being nested (`Club of Rome (2)` → `Club of Rome (3)`, never
 * `Club of Rome (2) (2)`).
 *
 * Pure (the caller supplies the availability callback), unit-tested.
 *
 * @license GPL-2.0-or-later
 */
final class LabelSuffix {

	/** Safety net against a callback that never frees a label. */
	private const MAX_ATTEMPTS = 1000;

	/**
	 * @param string $label the base label (without a duplicate suffix)
	 * @param callable(string):bool $isTaken receives the candidate suffix
	 *        (`" (2)"`, `" (3)"`, …) and returns true when the resulting
	 *        label/page is unavailable
	 * @return string the first free suffix, including its leading space
	 * @throws \RuntimeException when no free suffix is found
	 */
	public static function nextSuffix( string $label, callable $isTaken ): string {
		$base = rtrim( $label );
		$next = 2;
		if ( preg_match( '/^(.*) \((\d+)\)$/u', $base, $matches ) === 1 ) {
			$base = $matches[1];
			$next = (int)$matches[2] + 1;
		}
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++, $next++ ) {
			$suffix = ' (' . $next . ')';
			if ( !$isTaken( $suffix ) ) {
				return $suffix;
			}
		}
		throw new \RuntimeException( 'Could not find a free duplicate suffix for "' . $label . '".' );
	}

}
