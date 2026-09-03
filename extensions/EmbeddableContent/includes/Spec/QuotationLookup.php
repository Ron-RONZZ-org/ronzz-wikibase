<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\MediaWikiServices;

/**
 * MediaWiki-bound facade for the source-quotation listing (issue #79): runs
 * the QuotationFinder against the instance's WDQS endpoint (the
 * `sparqlUrl` config key; the entity-URI prefixes derive from $wgServer
 * like DuplicateChecker's). Shared by the Special:QuotationsOf page and the
 * `{{#quotations-of:}}` parser function so both surfaces behave
 * identically.
 *
 * Exception-safe by contract: an unreachable WDQS (or a config without the
 * content vocabulary / SPARQL endpoint) yields null — the caller degrades
 * to "no data", never a 500. The pure logic (query building, row parsing,
 * payload decoding) lives in QuotationFinder and is unit-tested without a
 * MediaWiki runtime (the DuplicateFinder pattern).
 *
 * @license GPL-2.0-or-later
 */
final class QuotationLookup {

	/**
	 * All quotations of the given source item, or null when the lookup
	 * cannot run (no SPARQL endpoint configured, WDQS unreachable, config
	 * shape). See QuotationFinder::findForSource for the row shape.
	 *
	 * @return array<int,array{qid:string,content:string,label:string}>|null
	 */
	public static function findForSource( EmbeddableContentConfig $config, string $sourceItemId ): ?array {
		$contentPropertyId = $config->payloadPropertyIds()['quotation'] ?? null;
		$sourcePropertyId = $config->provenancePropertyIds()['source'] ?? null;
		$quotationClassId = $config->classIds()['quotation'] ?? null;
		$endpoint = $config->sparqlUrl();
		if ( $contentPropertyId === null || $sourcePropertyId === null
			|| $quotationClassId === null || $endpoint === null
		) {
			return null;
		}
		$prefixes = self::entityPrefixes();
		if ( $prefixes === null ) {
			return null;
		}
		[ $wd, $wdt ] = $prefixes;
		$finder = new QuotationFinder(
			static fn ( string $query ): ?array => self::runSparql( $endpoint, $query )
		);
		return $finder->findForSource(
			$sourceItemId,
			$quotationClassId,
			$contentPropertyId,
			$sourcePropertyId,
			$config->instanceOfPropertyId(),
			$wd,
			$wdt
		);
	}

	/** @return array{string,string}|null [wd, wdt] entity URI bases, or null */
	private static function entityPrefixes(): ?array {
		$server = $GLOBALS['wgServer'] ?? '';
		if ( !is_string( $server ) || $server === '' ) {
			return null;
		}
		$wd = rtrim( $server, '/' ) . '/entity/';
		return [ $wd, str_replace( '/entity/', '/prop/direct/', $wd ) ];
	}

	/** @return array<int,array<string,mixed>>|null */
	private static function runSparql( string $endpoint, string $query ): ?array {
		try {
			$http = MediaWikiServices::getInstance()->getHttpRequestFactory();
			$request = $http->create(
				$endpoint,
				[ 'method' => 'POST', 'postData' => [ 'query' => $query ], 'timeout' => 10 ],
				__METHOD__
			);
			$request->setHeader( 'Accept', 'application/sparql-results+json' );
			if ( !$request->execute()->isOK() ) {
				return null;
			}
			$decoded = json_decode( $request->getContent(), true );
			if ( !is_array( $decoded ) || !isset( $decoded['results']['bindings'] ) ) {
				return null;
			}
			$rows = $decoded['results']['bindings'];
			return is_array( $rows ) ? $rows : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
