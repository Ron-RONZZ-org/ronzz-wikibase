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
	 * First SPARQL result row (website-class item) whose URL statement
	 * host-matches the root URL. Each row must be a decoded SPARQL binding:
	 * ['item' => 'https://…/entity/Q123', 'url' => 'https://example.org',
	 *  'label' => 'Example Domain' (optional)]. Returns null when nothing
	 * matches — the caller falls back to the site-name inference.
	 *
	 * @param array<int,array<string,array{type:string,value:string}>> $rows
	 * @return array{id:string,label:string}|null
	 */
	public static function findByHost( array $rows, string $root ): ?array {
		$targetHost = self::normalizeHost( $root );
		if ( $targetHost === '' ) {
			return null;
		}
		foreach ( $rows as $row ) {
			$url = $row['url']['value'] ?? '';
			if ( !is_string( $url ) || !self::hostMatches( $url, $targetHost ) ) {
				continue;
			}
			$item = (string)( $row['item']['value'] ?? '' );
			$qid = basename( $item );
			if ( preg_match( '/^Q[1-9]\d*$/i', $qid ) !== 1 ) {
				continue;
			}
			$label = (string)( $row['label']['value'] ?? '' );
			return [ 'id' => $qid, 'label' => $label !== '' ? $label : $qid ];
		}
		return null;
	}
}
