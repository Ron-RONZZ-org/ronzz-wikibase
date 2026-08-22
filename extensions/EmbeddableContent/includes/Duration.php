<?php

declare( strict_types = 1 );

namespace EmbeddableContent;

/**
 * Duration parsing/formatting for the source pages (Special:AddSource
 * film/song/video/YouTube classes): input is the standardized string
 * "(HH):MM:SS", storage is integer seconds (quantity datatype).
 *
 * @license GPL-2.0-or-later
 */
final class Duration {

	/**
	 * Parses "MM:SS" or "HH:MM:SS" into whole seconds; null on a malformed
	 * value (so callers can surface a form error instead of guessing).
	 */
	public static function parseSeconds( string $value ): ?int {
		$value = trim( $value );
		if ( $value === '' ) {
			return 0;
		}
		if ( preg_match( '/^(?:(\d+):)?([0-5]?\d):([0-5]\d)$/', $value, $m ) !== 1 ) {
			return null;
		}
		$hours = (int)( $m[1] ?? 0 );
		$minutes = (int)$m[2];
		$seconds = (int)$m[3];
		if ( $minutes > 59 || $seconds > 59 ) {
			return null;
		}
		return $hours * 3600 + $minutes * 60 + $seconds;
	}

	/**
	 * Formats seconds as "MM:SS", or "HH:MM:SS" when an hour component is
	 * present (hours omitted when 0 — the input format's optional HH).
	 */
	public static function formatSeconds( int $seconds ): string {
		$hours = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$secs = $seconds % 60;
		return $hours > 0
			? sprintf( '%d:%02d:%02d', $hours, $minutes, $secs )
			: sprintf( '%02d:%02d', $minutes, $secs );
	}
}
