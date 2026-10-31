<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Normalizes a Wikibase TimeValue string to a signed `[+-]YYYY-MM-DD` day
 * string, and validates/normalizes the signed day strings the Add* / Update*
 * forms submit.
 *
 * A TimeValue stores its precision in `00` placeholders — a year-precision
 * date is `+1970-00-00T00:00:00Z`, a month-precision one `+1970-06-00T…`.
 * The forms need a real day, and the Add* harvest already normalizes
 * `00` → `01` (`Fetch\WikidataCore::timeValue`), so the Update* prefill must
 * produce the SAME value, or a year-precision date comes back as the
 * unparseable `YYYY-00-00` (the AddPerson date report).
 *
 * BCE dates keep the TimeValue's leading minus (`-0384-01-01`), matching the
 * signed form field the AddPerson/UpdatePerson forms now submit.
 *
 * Pure — no MediaWiki runtime — so it is unit-testable standalone.
 *
 * @license GPL-2.0-or-later
 */
final class WikibaseTime {

	/** A signed day string as the forms submit it (`-0384-01-01`). */
	private const DAY_PATTERN = '/^([+-]?)(\d{4})-(\d{2})-(\d{2})$/';

	/** A TimeValue `getTime()` prefix (`+1970-00-00T…` / `-0384-00-00T…`). */
	private const TIME_PATTERN = '/^([+-])(\d{4})-(\d{2})-(\d{2})T/';

	/**
	 * @param string $time a TimeValue `getTime()` string (e.g.
	 *  "+1970-00-00T00:00:00Z" or "-0384-00-00T00:00:00Z")
	 * @return string|null the signed "YYYY-MM-DD" day (00 placeholders
	 *  normalized to 01; BCE keeps the leading minus), or null when the string
	 *  is not a day-or-coarser date
	 */
	public static function isoDay( string $time ): ?string {
		if ( preg_match( self::TIME_PATTERN, $time, $m ) !== 1 ) {
			return null;
		}
		$month = $m[3] === '00' ? '01' : $m[3];
		$day = $m[4] === '00' ? '01' : $m[4];
		return sprintf( '%s%s-%s-%s', $m[1] === '-' ? '-' : '', $m[2], $month, $day );
	}

	/**
	 * Whether a submitted string is a valid day (`YYYY-MM-DD` or the BCE form
	 * `-YYYY-MM-DD`). Year 0 is rejected (there is no year 0 in the
	 * Gregorian/Julian historical numbering used by Wikibase), as are
	 * impossible months/days.
	 *
	 * @param string $date the form value
	 */
	public static function isSignedDay( string $date ): bool {
		return self::parts( $date ) !== null;
	}

	/**
	 * Parses a submitted signed day into its parts.
	 *
	 * @param string $date the form value
	 * @return array{negative:bool,year:int,month:int,day:int}|null
	 */
	public static function parts( string $date ): ?array {
		if ( preg_match( self::DAY_PATTERN, trim( $date ), $m ) !== 1 ) {
			return null;
		}
		$year = (int)$m[2];
		$month = (int)$m[3];
		$day = (int)$m[4];
		if ( $m[1] === '-' && $year === 0 ) {
			return null;
		}
		if ( !checkdate( $month, $day, $year ) ) {
			return null;
		}
		return [
			'negative' => $m[1] === '-',
			'year' => $year,
			'month' => $month,
			'day' => $day,
		];
	}
}
