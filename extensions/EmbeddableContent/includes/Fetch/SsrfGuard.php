<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * SSRF guard for arbitrary contributor-entered URLs (the website/webpage
 * metadata fetch). Pure literal checks — no DNS resolution here: the
 * resolution-based private-IP rejection happens at connect time in the
 * transport (MediaWiki's HttpRequestFactory `rejectLocalUrls`), so this
 * class stays deterministic and unit-testable.
 *
 * Rejects: non-http(s) schemes, empty/invalid hosts, embedded credentials,
 * `localhost`/`.local`/`.internal` hostnames, private/reserved IP literals,
 * and malformed ports. Returns a normalized URL, or null when unsafe.
 *
 * @license GPL-2.0-or-later
 */
final class SsrfGuard {

	public static function validate( string $url ): ?string {
		$url = trim( $url );
		if ( $url === '' || preg_match( '#^https?://#i', $url ) !== 1 ) {
			return null;
		}
		$parts = parse_url( $url );
		if ( $parts === false || !isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( $scheme !== 'http' && $scheme !== 'https' ) {
			return null;
		}
		$host = (string)$parts['host'];
		if ( $host === '' || !self::isPublicHost( $host ) ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null; // credential-bearing URLs are a redirect/phishing hazard
		}
		if ( isset( $parts['port'] ) && ( !is_numeric( $parts['port'] ) || (int)$parts['port'] < 1 || (int)$parts['port'] > 65535 ) ) {
			return null;
		}
		return self::normalize( $url );
	}

	/**
	 * Site root of a validated URL ("https://www.example.com"), used to
	 * collapse a page URL down to its website for the /website class.
	 */
	public static function siteRoot( string $url ): string {
		$parts = parse_url( $url );
		$root = strtolower( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );
		if ( isset( $parts['port'] ) ) {
			$root .= ':' . $parts['port'];
		}
		return $root;
	}

	/**
	 * Hostname sanity: not localhost/private-label, not a private or
	 * reserved IP literal, syntactically a real hostname (dot-separated
	 * labels).
	 */
	public static function isPublicHost( string $host ): bool {
		$host = strtolower( rtrim( trim( $host ), '.' ) );
		if ( $host === '' ) {
			return false;
		}
		if ( $host === 'localhost' || str_ends_with( $host, '.localhost' ) ) {
			return false;
		}
		foreach ( [ '.local', '.internal', '.home.arpa', '.lan', '.test', '.invalid' ] as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return false;
			}
		}
		// parse_url returns IPv6 hosts WITH their brackets — strip them for
		// the literal check.
		$ip = $host[0] === '[' && str_ends_with( $host, ']' ) ? substr( $host, 1, -1 ) : $host;
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return self::isPublicIpv4( $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return self::isPublicIpv6( $ip );
		}
		if ( strpos( $host, '.' ) === false ) {
			return false; // bare single-label names resolve to intranet hosts
		}
		return preg_match(
			'/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
			$host
		) === 1;
	}

	/** @param string $ip dotted-quad IPv4 literal */
	private static function isPublicIpv4( string $ip ): bool {
		$n = array_map( 'intval', explode( '.', $ip ) );
		[ $a, $b, $c, $d ] = $n + [ 0, 0, 0, 0 ];
		if ( $a === 0 || $a === 10 || $a === 127 ) {
			return false; // "this network", private, loopback
		}
		if ( $a === 100 && $b >= 64 && $b <= 127 ) {
			return false; // CGNAT
		}
		if ( $a === 169 && $b === 254 ) {
			return false; // link-local
		}
		if ( $a === 172 && $b >= 16 && $b <= 31 ) {
			return false; // private
		}
		if ( $a === 192 && $b === 168 ) {
			return false; // private
		}
		if ( $a === 192 && $b === 0 && $c === 0 ) {
			return false; // IETF protocol assignments
		}
		if ( $a === 192 && $b === 0 && $c === 2 ) {
			return false; // TEST-NET-1
		}
		if ( $a === 198 && ( $b === 18 || $b === 19 ) ) {
			return false; // benchmarking
		}
		if ( $a === 198 && $b === 51 && $c === 100 ) {
			return false; // TEST-NET-2
		}
		if ( $a === 203 && $b === 0 && $c === 113 ) {
			return false; // TEST-NET-3
		}
		if ( $a >= 224 ) {
			return false; // multicast + reserved
		}
		return true;
	}

	/** @param string $ip IPv6 literal */
	private static function isPublicIpv6( string $ip ): bool {
		$bin = inet_pton( $ip );
		if ( $bin === false ) {
			return false;
		}
		if ( $bin === inet_pton( '::' ) || $bin === inet_pton( '::1' ) ) {
			return false; // unspecified, loopback
		}
		// fc00::/7 (ULA), fe80::/10 (link-local), ff00::/8 (multicast)
		$first = ord( $bin[0] );
		if ( ( $first & 0xFE ) === 0xFC || ( $first & 0xFF ) === 0xFF ) {
			return false;
		}
		if ( ( $first & 0xFF ) === 0xFE && ( ord( $bin[1] ) & 0xC0 ) === 0x80 ) {
			return false;
		}
		// 2001:db8::/32 documentation range
		if ( $first === 0x20 && ord( $bin[1] ) === 0x01 && ord( $bin[2] ) === 0x0D && ord( $bin[3] ) === 0xB8 ) {
			return false;
		}
		return true;
	}

	/** Rebuilds a clean URL (drops the fragment). */
	private static function normalize( string $url ): string {
		$parts = parse_url( $url );
		$clean = strtolower( $parts['scheme'] ) . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$clean .= ':' . $parts['port'];
		}
		$clean .= $parts['path'] ?? '';
		if ( isset( $parts['query'] ) ) {
			$clean .= '?' . $parts['query'];
		}
		return $clean;
	}
}
