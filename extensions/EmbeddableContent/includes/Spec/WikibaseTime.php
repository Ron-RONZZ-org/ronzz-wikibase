<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Normalizes a Wikibase TimeValue string to a `YYYY-MM-DD` day string.
 *
 * A TimeValue stores its precision in `00` placeholders — a year-precision
 * date is `+1970-00-00T00:00:00Z`, a month-precision one
 * `+1970-06-00T…`. The date widgets (and the flow's statement specs) need a
 * real day, and the Add* harvest already normalizes `00` → `01`
 * (`Fetch\WikidataCore::timeValue`), so the Update* prefill must produce the
 * SAME value, or a year-precision date comes back as the unwidget-parseable
 * `YYYY-00-00` (the AddPerson date report).
 *
 * Pure — no MediaWiki runtime — so it is unit-testable standalone.
 *
 * @license GPL-2.0-or-later
 */
final class WikibaseTime {

	/**
	 * @param string $time a TimeValue `getTime()` string (e.g.
	 *  "+1970-00-00T00:00:00Z")
	 * @return string|null the "YYYY-MM-DD" day (00 placeholders normalized to
	 *  01), or null when the string is not a day-or-coarser date
	 */
	public static function isoDay( string $time ): ?string {
		if ( preg_match( '/^\+(\d{4})-(\d{2})-(\d{2})T/', $time, $m ) !== 1 ) {
			return null;
		}
		$month = $m[2] === '00' ? '01' : $m[2];
		$day = $m[3] === '00' ? '01' : $m[3];
		return sprintf( '%s-%s-%s', $m[1], $month, $day );
	}

}
