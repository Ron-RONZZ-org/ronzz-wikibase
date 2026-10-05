<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\EmbeddableContentConfig;
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
	 * shape). See QuotationFinder::findForPredicate for the row shape.
	 *
	 * @return array<int,array{qid:string,content:string,label:string}>|null
	 */
	public static function findForSource( EmbeddableContentConfig $config, string $sourceItemId ): ?array {
		return self::findByPredicate( $config, $sourceItemId, 'source' );
	}

	/**
	 * All quotations ATTRIBUTED TO the given item (an author / person),
	 * through the `attributed to` provenance property. Same row shape and
	 * contract as findForSource.
	 *
	 * @return array<int,array{qid:string,content:string,label:string}>|null
	 */
	public static function findByAuthor( EmbeddableContentConfig $config, string $authorItemId ): ?array {
		return self::findByPredicate( $config, $authorItemId, 'attributedTo' );
	}

	/**
	 * Shared facade for the predicate-based quotation lookups: resolves the
	 * vocabulary + endpoint, runs the QuotationFinder, and degrades to null
	 * on any failure (never a 500).
	 *
	 * @param string $predicateKey provenance config key ('source' |
	 *   'attributedTo')
	 * @return array<int,array{qid:string,content:string,label:string}>|null
	 */
	private static function findByPredicate(
		EmbeddableContentConfig $config,
		string $itemId,
		string $predicateKey
	): ?array {
		try {
			$contentPropertyId = $config->payloadPropertyIds()['quotation'] ?? null;
			$predicatePropertyId = $config->provenancePropertyIds()[$predicateKey] ?? null;
			$quotationClassId = $config->classIds()['quotation'] ?? null;
			$endpoint = $config->sparqlUrl();
			if ( $contentPropertyId === null || $predicatePropertyId === null
				|| $quotationClassId === null || $endpoint === null
			) {
				// Diagnosable: name the missing vocabulary piece rather than
				// degrading silently (the page already shows "unavailable").
				error_log( 'QuotationLookup: content vocabulary incomplete for ' . $predicateKey . ' ' . $itemId
					. ' (payload=' . var_export( $contentPropertyId, true )
					. ' ' . $predicateKey . '=' . var_export( $predicatePropertyId, true )
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
			return $finder->findForPredicate(
				$itemId,
				$predicatePropertyId,
				$quotationClassId,
				$contentPropertyId,
				$config->instanceOfPropertyId(),
				$wd,
				$wdt
			);
		} catch ( \Throwable $e ) {
			// A malformed/absent content vocabulary or endpoint degrades to
			// "no data" — never a 500 (the DuplicateChecker contract). The
			// failure stays observable (php-fpm stderr → the nginx error log).
			error_log( 'QuotationLookup: findByPredicate(' . $predicateKey . ', ' . $itemId . ') failed: '
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
	 * Refreshes the parser cache of the given items' classic pages (the
	 * "Quotations" auto-link row on the Source: pages, and now the Person:
	 * pages' quotations-by row). Adding, updating or re-sourcing a
	 * quotation does NOT touch the source's/author's item revision, so the
	 * parser-cache dependency (ParserOutput::addTemplate) never fires for
	 * that change — the page must be invalidated explicitly. Called by the
	 * content-creation paths (SpecialAddContentItem,
	 * SpecialUpdateContentItem, ApiAddSpecialContent) when their record
	 * carries a `source` or an `attributedTo`.
	 *
	 * Best-effort: a failure (no sitelink, DB hiccup) only delays the row
	 * refresh until the parser-cache TTL — it never breaks the item save.
	 *
	 * @param string[] $itemIds source and/or author item ids
	 */
	public static function invalidateClassicPages( array $itemIds ): void {
		foreach ( array_unique( array_filter( $itemIds, 'is_string' ) ) as $itemId ) {
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
		// Direct cURL, not MediaWiki's HttpRequestFactory: the php-fpm POST
		// transport mangled multi-line queries into Blazegraph (see
		// SparqlRunner). GET with the query URL-parameter is the
		// WDQS-standard read form.
		try {
			return SparqlRunner::select( $endpoint, $query );
		} catch ( \Throwable $e ) {
			error_log( 'QuotationLookup: SPARQL request to ' . $endpoint . ' threw '
				. get_class( $e ) . ': ' . $e->getMessage() );
			return null;
		}
	}
}
