<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Normalized-host matching for the webpage→website parent inference.
 *
 * The webpage URL's site root is matched against the URL statements of
 * existing website-class items — deterministic, no metadata fetch needed.
 * "Normalized" here means: lowercase, trailing-dot stripped, the `www.`
 * prefix collapsed (www.example.com and example.com are the same site for
 * the purposes of the auto-assigned parent; the user can still correct the
 * combobox before submit), default ports dropped by parse_url, and any
 * path/query fragment ignored (we compare hosts only).
 *
 * The match is ANCESTOR-DOMAIN aware: a webpage on a subdomain
 * (scifa.univ-lorraine.fr) is part of the site whose host is the subdomain
 * itself OR any recorded ancestor domain (univ-lorraine.fr). The most
 * specific recorded host wins (the page's own host beats any parent
 * domain; a deep subdomain matches the closest recorded ancestor), so the
 * inference works for deep subdomains and www2-style hosts too. The match
 * only ever goes UP the page host's own suffix chain — a recorded host
 * that is neither the page host nor one of its parent domains never
 * matches (example.org is never the parent of example.com).
 *
 * @license GPL-2.0-or-later
 */
class SiteRootMatcher {

	/**
	 * Normalized host of a URL ("https://www.Example.com/a/b" →
	 * "example.com"); '' for an unparseable URL or one without a host.
	 */
	public static function normalizeHost( string $url ): string {
		$parts = parse_url( $url );
		if ( $parts === false || !isset( $parts['host'] ) || !is_string( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( rtrim( $parts['host'], '.' ) );
		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return $host;
	}

	/**
	 * Whether a URL's normalized host equals the target normalized host.
	 * Never matches an unparseable URL.
	 */
	public static function hostMatches( string $url, string $normalizedHost ): bool {
		$host = self::normalizeHost( $url );
		return $host !== '' && $host === $normalizedHost;
	}

	/**
	 * Whether a URL's normalized host is the target host OR one of its
	 * parent domains — the recorded website host is the page host itself
	 * or an ancestor ("univ-lorraine.fr" is a parent host of
	 * "scifa.univ-lorraine.fr"). www-collapse applies to BOTH sides
	 * (normalizeHost). A non-ancestor (sibling, unrelated, a SUBdomain of
	 * the recorded host — "page" is never a parent of "site") never
	 * matches.
	 */
	public static function hostIsSelfOrAncestor( string $url, string $pageHost ): bool {
		$host = self::normalizeHost( $url );
		return $host !== '' && ( $host === $pageHost || str_ends_with( $pageHost, '.' . $host ) );
	}

	/**
	 * Best SPARQL result row (website-class item) whose URL statement
	 * host-matches the root URL — where "host-matches" means the recorded
	 * host is the page root's host or an ancestor domain of it. Among the
	 * matching rows the LONGEST host wins (the most specific site: the
	 * page's own host when a record exists, else the closest recorded
	 * parent domain); equal-length ties keep the first row. Each row must
	 * be a decoded SPARQL binding: ['item' => 'https://…/entity/Q123',
	 * 'url' => 'https://example.org', 'label' => 'Example Domain'
	 * (optional)]. Returns null when nothing matches — the caller falls
	 * back to the site-name inference.
	 *
	 * @param array<int,array<string,array{type:string,value:string}>> $rows
	 * @return array{id:string,label:string}|null
	 */
	public static function findByHost( array $rows, string $root ): ?array {
		$pageHost = self::normalizeHost( $root );
		if ( $pageHost === '' ) {
			return null;
		}
		$best = null;
		$bestHostLength = 0;
		foreach ( $rows as $row ) {
			$url = $row['url']['value'] ?? '';
			if ( !is_string( $url ) || !self::hostIsSelfOrAncestor( $url, $pageHost ) ) {
				continue;
			}
			$host = self::normalizeHost( $url );
			if ( $host === '' ) {
				continue;
			}
			$item = (string)( $row['item']['value'] ?? '' );
			$qid = basename( $item );
			if ( preg_match( '/^Q[1-9]\d*$/i', $qid ) !== 1 ) {
				continue;
			}
			if ( $best !== null && strlen( $host ) <= $bestHostLength ) {
				continue; // a more specific (longer) recorded host already won
			}
			$label = (string)( $row['label']['value'] ?? '' );
			$best = [ 'id' => $qid, 'label' => $label !== '' ? $label : $qid ];
			$bestHostLength = strlen( $host );
		}
		return $best;
	}
}
