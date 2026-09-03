<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Minimal WDQS SPARQL SELECT runner for the extension's server-side
 * lookups (QuotationLookup, DuplicateChecker, SiteRootMatcher callers).
 *
 * Deliberately bypasses MediaWiki's HttpRequestFactory: its php-fpm POST
 * transport mangled multi-line queries on the way to Blazegraph
 * ("Lexical error … Encountered: '\\'" — a literal backslash-n reached the
 * parser; reproduced against the public sparql URL AND the localhost WDQS,
 * CLI worked, php-fpm did not). A direct cURL GET with the query as an
 * http_build_query URL parameter is the WDQS-standard read form, is encoded
 * by PHP itself and never goes through a body transport.
 *
 * Exception-safe by contract: an unreachable or rejecting endpoint yields
 * null (callers degrade to "no data"), and the failure is logged to
 * php-fpm stderr (the nginx error log) — never a 500.
 *
 * @license GPL-2.0-or-later
 */
final class SparqlRunner {

	public const TIMEOUT_SECONDS = 20;

	/**
	 * @return array<int,array<string,mixed>>|null decoded `results.bindings`,
	 *         or null when the endpoint is unreachable / rejects the query /
	 *         the response is not SPARQL JSON
	 */
	public static function select( string $endpoint, string $query, int $timeout = self::TIMEOUT_SECONDS ): ?array {
		if ( !function_exists( 'curl_init' ) ) {
			error_log( 'SparqlRunner: curl extension not available' );
			return null;
		}
		$url = $endpoint . ( strpos( $endpoint, '?' ) === false ? '?' : '&' )
			. http_build_query( [ 'query' => $query ] );

		$ch = curl_init( $url );
		if ( $ch === false ) {
			error_log( 'SparqlRunner: curl_init failed for ' . $endpoint );
			return null;
		}
		$version = (string)( $GLOBALS['wgVersion'] ?? 'unknown' );
		curl_setopt_array( $ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_CONNECTTIMEOUT => min( 5, $timeout ),
			CURLOPT_USERAGENT => 'MediaWiki/' . $version . ' (EmbeddableContent; ' . ( $GLOBALS['wgServer'] ?? 'wikibase' ) . ')',
			CURLOPT_HTTPHEADER => [ 'Accept: application/sparql-results+json' ],
			CURLOPT_FOLLOWLOCATION => false,
		] );
		$body = curl_exec( $ch );
		$errno = curl_errno( $ch );
		$status = (int)curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );

		if ( $body === false ) {
			error_log( 'SparqlRunner: request to ' . $endpoint . ' failed (curl errno ' . $errno . ')' );
			return null;
		}
		if ( $status < 200 || $status >= 300 ) {
			error_log( 'SparqlRunner: request to ' . $endpoint . ' failed with status ' . $status
				. ' body: ' . substr( (string)$body, 0, 8000 ) );
			return null;
		}
		$decoded = json_decode( (string)$body, true );
		if ( !is_array( $decoded ) || !isset( $decoded['results']['bindings'] ) ) {
			error_log( 'SparqlRunner: response from ' . $endpoint . ' was not application/sparql-results+json ('
				. substr( (string)$body, 0, 120 ) . ')' );
			return null;
		}
		$rows = $decoded['results']['bindings'];
		return is_array( $rows ) ? $rows : null;
	}
}
