<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

/**
 * Builds the CSL-JSON for a WIKI PAGE citation — distinct from a Wikibase
 * item citation (issues #24/#25). A classic content page (e.g.
 * `Orthonormality`) is cited as the page itself, not its sitelinked item:
 *
 *   - `type`: webpage
 *   - `title`: the page's prefixed title
 *   - `container-title`: the site name (`$wgSitename`)
 *   - `URL`: the page's canonical URL
 *   - `issued`: the page's LAST revision date (the page's "last edition");
 *     APA renders the full date, Vancouver the year
 *   - no author
 *
 * Pure PHP (no MediaWiki) so it is unit-testable; the caller resolves the
 * title / URL / site name / last-revision timestamp.
 *
 * @license GPL-2.0-or-later
 */
final class PageCitationBuilder {

	/**
	 * @param string $title the page's prefixed title
	 * @param string $url the page's canonical URL
	 * @param string|null $siteName the wiki site name (container title)
	 * @param string|null $timestamp the last-revision timestamp (MediaWiki
	 *  TS_MW `YYYYMMDDHHMMSS`, or an ISO date); only Y/M/D is used
	 * @return array<string,mixed> CSL-JSON
	 */
	public static function build( string $title, string $url, ?string $siteName, ?string $timestamp ): array {
		$csl = [
			'type' => 'webpage',
			'title' => $title,
		];
		if ( $siteName !== null && trim( $siteName ) !== '' ) {
			$csl['container-title'] = $siteName;
		}
		if ( trim( $url ) !== '' ) {
			$csl['URL'] = $url;
		}
		$dateParts = self::dateParts( $timestamp );
		if ( $dateParts !== null ) {
			$csl['issued'] = [ 'date-parts' => [ $dateParts ] ];
		}
		return $csl;
	}

	/**
	 * Parses the year (and month/day when present) out of a MediaWiki or ISO
	 * timestamp; null when no usable year is found.
	 *
	 * @return int[]|null [Y] | [Y, M] | [Y, M, D]
	 */
	private static function dateParts( ?string $timestamp ): ?array {
		if ( $timestamp === null || trim( $timestamp ) === '' ) {
			return null;
		}
		$value = ltrim( trim( $timestamp ), '+' );
		foreach ( [ '/^(\d{4})(\d{2})(\d{2})/', '/^(\d{4})-(\d{2})-(\d{2})/' ] as $pattern ) {
			if ( preg_match( $pattern, $value, $m ) === 1 ) {
				$year = (int)$m[1];
				if ( $year <= 0 ) {
					return null;
				}
				$month = (int)$m[2];
				$day = (int)$m[3];
				if ( $month >= 1 && $day >= 1 ) {
					return [ $year, $month, $day ];
				}
				return $month >= 1 ? [ $year, $month ] : [ $year ];
			}
		}
		if ( preg_match( '/^(\d{4})/', $value, $m ) === 1 ) {
			$year = (int)$m[1];
			return $year > 0 ? [ $year ] : null;
		}
		return null;
	}

}
