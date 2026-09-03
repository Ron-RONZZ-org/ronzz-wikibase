<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

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
		try {
			$contentPropertyId = $config->payloadPropertyIds()['quotation'] ?? null;
			$sourcePropertyId = $config->provenancePropertyIds()['source'] ?? null;
			$quotationClassId = $config->classIds()['quotation'] ?? null;
			$endpoint = $config->sparqlUrl();
			if ( $contentPropertyId === null || $sourcePropertyId === null
				|| $quotationClassId === null || $endpoint === null
			) {
				// Diagnosable: name the missing vocabulary piece rather than
				// degrading silently (the page already shows "unavailable").
				error_log( 'QuotationLookup: content vocabulary incomplete for source ' . $sourceItemId
					. ' (payload=' . var_export( $contentPropertyId, true )
					. ' source=' . var_export( $sourcePropertyId, true )
					. ' class=' . var_export( $quotationClassId, true )
					. ' sparqlUrl=' . var_export( $endpoint, true ) . ')' );
				return null;
			}
			$prefixes = self::entityPrefixes();
			if ( $prefixes === null ) {
				error_log( 'QuotationLookup: wgServer not set — cannot derive entity prefixes' );
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
		} catch ( \Throwable $e ) {
			// A malformed/absent content vocabulary or endpoint degrades to
			// "no data" — never a 500 (the DuplicateChecker contract). The
			// failure stays observable (php-fpm stderr → the nginx error log).
			error_log( 'QuotationLookup: findForSource(' . $sourceItemId . ') failed: '
				. get_class( $e ) . ': ' . $e->getMessage() );
			return null;
		}
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

	/**
	 * Refreshes the parser cache of the given source items' classic pages
	 * (the "Quotations" auto-link row on the Source: pages). Adding,
	 * updating or re-sourcing a quotation does NOT touch the source item's
	 * revision, so the parser-cache dependency (ParserOutput::addTemplate)
	 * never fires for that change — the page must be invalidated
	 * explicitly. Called by the content-creation paths
	 * (SpecialAddContentItem, SpecialUpdateContentItem,
	 * ApiAddSpecialContent) when their record carries a `source`.
	 *
	 * Best-effort: a failure (no sitelink, DB hiccup) only delays the row
	 * refresh until the parser-cache TTL — it never breaks the item save.
	 *
	 * @param string[] $sourceItemIds
	 */
	public static function invalidateSourcePages( array $sourceItemIds ): void {
		foreach ( array_unique( array_filter( $sourceItemIds, 'is_string' ) ) as $itemId ) {
			if ( preg_match( '/^Q[1-9]\d*$/i', $itemId ) !== 1 ) {
				continue;
			}
			try {
				$link = WikibaseRepo::getStore()->newSiteLinkStore()
					->getLinkForItemId( new ItemId( $itemId ) );
				$title = Title::newFromText( (string)( $link['pageName'] ?? '' ) );
				if ( $title !== null && $title->exists() ) {
					$title->invalidateCache();
				}
			} catch ( \Throwable $e ) {
				// Best-effort (see above).
			}
		}
	}

	/** @return array<int,array<string,mixed>>|null */
	private static function runSparql( string $endpoint, string $query ): ?array {
		try {
			$http = MediaWikiServices::getInstance()->getHttpRequestFactory();
			// GET with the query in the URL string. MediaWiki's POST bodies
			// are sent by different transports in CLI vs php-fpm (curl vs
			// Guzzle) and the php-fpm transport mangled the multi-line
			// query into Blazegraph ("Lexical error … Encountered: '\\'" —
			// a literal \n reached the parser); a GET query parameter is
			// encoded by http_build_query and never goes through a body
			// transport. WDQS accepts GET (the runbook's own status
			// commands use it).
			$url = $endpoint . ( strpos( $endpoint, '?' ) === false ? '?' : '&' )
				. http_build_query( [ 'query' => $query ] );
			$request = $http->create( $url, [ 'method' => 'GET', 'timeout' => 20 ], __METHOD__ );
			$request->setHeader( 'Accept', 'application/sparql-results+json' );
			if ( !$request->execute()->isOK() ) {
				error_log( 'QuotationLookup: SPARQL request to ' . $endpoint . ' failed with status '
					. $request->getStatus() . ' body: ' . substr( (string)$request->getContent(), 0, 2000 ) );
				return null;
			}
			$decoded = json_decode( $request->getContent(), true );
			if ( !is_array( $decoded ) || !isset( $decoded['results']['bindings'] ) ) {
				error_log( 'QuotationLookup: SPARQL response from ' . $endpoint . ' was not '
					. 'application/sparql-results+json (' . substr( (string)$request->getContent(), 0, 120 ) . ')' );
				return null;
			}
			$rows = $decoded['results']['bindings'];
			return is_array( $rows ) ? $rows : null;
		} catch ( \Throwable $e ) {
			error_log( 'QuotationLookup: SPARQL request to ' . $endpoint . ' threw '
				. get_class( $e ) . ': ' . $e->getMessage() );
			return null;
		}
	}
}
