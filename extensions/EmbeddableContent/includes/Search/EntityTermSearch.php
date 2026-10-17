<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Search;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\EntityClassFilter;
use MediaWiki\MediaWikiServices;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Case-insensitive CONTAINS search over the Wikibase term store
 * (`wbt_*` labels + aliases), shared by:
 *
 *  - `action=entitysearch` (the Add* pages / Special:Upload comboboxes —
 *    the wbsearchentities replacement);
 *  - the `Special:Search` "Items" section (`Hooks::onSpecialSearchResultsAppend`),
 *    which surfaces entity labels that core SearchMySQL cannot index (item
 *    pages carry no page text).
 *
 * Why not wbsearchentities: this instance's term store (no CirrusSearch)
 * matches labels/aliases EXACT-then-PREFIX — searching "AGPL" never finds
 * "GNU AGPL-3.0" and "Einstein" never finds "Albert Einstein", while
 * "Ring" never finds "2.2: Rings" (the numeric prefix defeats prefix
 * matching). This service runs a case-INSENSITIVE CONTAINS match
 * (`LIKE %term%` over `CONVERT(wbx_text USING utf8mb4)`) over the same
 * `wbt_*` term tables Wikibase's own DatabaseMatchingTermsLookup reads,
 * in-process and read-only. The term store stores `wbx_text` as VARBINARY
 * (case-sensitive, upstream T242644); the CONVERT to utf8mb4 gives a
 * case-insensitive collation.
 *
 * The `wbt_*` schema is Wikibase-internal (stable across 1.4x; upstream
 * ADR 0027 dropped the `wbt_type` table, so the label/alias type ids are
 * hardcoded from `Wikibase\Lib\Store\Sql\Terms\TermTypeIds` rather than
 * joined). Single-source local instance assumed — the tables live in the
 * wiki database (`repoDatabase=false`).
 *
 * Class scoping (the combobox scope) runs the term match first, then
 * over-fetches candidates and filters them in PHP by `instance of` (the
 * claim value is a blob, not a queryable column). The over-fetch is bounded
 * by CLASS_FILTER_FACTOR / MAX_SCAN.
 *
 * @license GPL-2.0-or-later
 */
class EntityTermSearch {

	/** Term-type ids for label + alias (Wikibase TermTypeIds). */
	private const TERM_TYPE_IDS = [ 1, 3 ];

	/**
	 * Candidate over-fetch multiplier for a class-scoped search: the term
	 * match runs first, then the class filter drops non-members, so more
	 * candidates are fetched to still fill the caller's limit.
	 */
	private const CLASS_FILTER_FACTOR = 5;

	/** Hard cap on candidate rows scanned for a class-scoped search. */
	private const MAX_SCAN = 200;

	private EmbeddableContentConfig $config;

	public function __construct( EmbeddableContentConfig $config ) {
		$this->config = $config;
	}

	/**
	 * Search item labels/aliases for `$term`.
	 *
	 * @param string $term the typed text (CONTAINS, case-insensitive)
	 * @param string $language display-label language
	 * @param int $limit maximum entries returned
	 * @param string[] $classIds restrict to items `instance of` one of these; [] = no filter
	 * @return array<int,array{id:string,label?:string,description?:string}>
	 *         wbsearchentities-shaped entries (id + optional label/description)
	 */
	public function search( string $term, string $language, int $limit, array $classIds = [] ): array {
		if ( trim( $term ) === '' || $limit <= 0 ) {
			return [];
		}
		$fetchLimit = $classIds === []
			? $limit * 2 + 1
			: min( $limit * self::CLASS_FILTER_FACTOR, self::MAX_SCAN ) + 1;

		$rows = $this->containsRows( $term, $fetchLimit );
		return $this->displayEntries( $rows, $language, $limit, $classIds );
	}

	/**
	 * Contains-match rows (item id => matched text) for `$search`, deduped by
	 * item id. One LIKE %term% query over label + alias terms, matched
	 * case-insensitively against the utf8mb4 projection of the VARBINARY
	 * term column — the same idiom as ApiFileSearch's page-title CONTAINS
	 * match.
	 *
	 * Raw SQL is used (not IExpression::LIKE/LikeValue) because the
	 * expression builder escapes a plain string as a literal and cannot
	 * express the CONVERT; MySQL/MariaDB is assumed (the instance + the
	 * dev/CI stack). The typed term is escaped (!% / !_ / !!) so a literal
	 * '%' or '_' does not become a wildcard.
	 *
	 * @return array<string,string> item id => first matched text
	 */
	private function containsRows( string $search, int $fetchLimit ): array {
		$services = MediaWikiServices::getInstance();
		$source = WikibaseRepo::getLocalEntitySource();
		// getDatabaseName() is string|false — false = the wiki database
		// (repoDatabase=false for the local source); getConnection() takes
		// the same string|false domain, so pass it through unchanged.
		$dbr = $services->getDBLoadBalancer()->getConnection(
			DB_REPLICA,
			[],
			$source->getDatabaseName()
		);

		$escape = static fn ( string $term ): string =>
			strtr( $term, [ '!' => '!!', '%' => '!%', '_' => '!_' ] );
		$pattern = '%' . $escape( $search ) . '%';

		$rows = [];
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( [ 'wbit_item_id', 'wbx_text' ] )
			->from( 'wbt_item_terms' )
			->join( 'wbt_term_in_lang', null, 'wbit_term_in_lang_id=wbtl_id' )
			->join( 'wbt_text_in_lang', null, 'wbtl_text_in_lang_id=wbxl_id' )
			->join( 'wbt_text', null, 'wbxl_text_id=wbx_id' )
			->where( 'CONVERT(wbx_text USING utf8mb4) LIKE '
				. $dbr->addQuotes( $pattern ) . " ESCAPE '!'" )
			->where( [ 'wbtl_type_id' => self::TERM_TYPE_IDS ] )
			->limit( $fetchLimit );
		foreach ( $queryBuilder->caller( __METHOD__ )->fetchResultSet() as $row ) {
			if ( !isset( $rows[$row->wbit_item_id] ) ) {
				$rows[$row->wbit_item_id] = $row->wbx_text;
			}
		}
		return $rows;
	}

	/**
	 * Builds the wbsearchentities-shaped entries for the matched item ids:
	 * label/description resolved in the requested language with the
	 * instance's fallback order (a matched ALIAS still displays the item's
	 * label). Missing items (deleted between query and load) are skipped.
	 *
	 * @param array<string,string> $rows item id => matched text
	 * @param string[] $classIds restrict to items instance-of one of these; [] = no filter
	 * @return array<int,array{id:string,label?:string,description?:string}>
	 */
	private function displayEntries( array $rows, string $language, int $limit, array $classIds = [] ): array {
		$lookup = WikibaseRepo::getEntityLookup();
		$fallback = $this->config->fallbackLanguages();
		$entries = [];
		foreach ( array_keys( $rows ) as $numericId ) {
			if ( count( $entries ) >= $limit ) {
				break;
			}
			$item = $lookup->getEntity( new ItemId( 'Q' . $numericId ) );
			if ( !$item instanceof Item ) {
				continue;
			}
			if ( $classIds !== []
				&& !EntityClassFilter::hasAnyClass( $item, $classIds, $this->config->instanceOfPropertyId() )
			) {
				continue;
			}
			$entry = [ 'id' => $item->getId()->getSerialization() ];
			$label = $this->termIn( $item->getLabels()->toTextArray(), $language, $fallback );
			if ( $label !== '' ) {
				$entry['label'] = $label;
			}
			$description = $this->termIn( $item->getDescriptions()->toTextArray(), $language, $fallback );
			if ( $description !== '' ) {
				$entry['description'] = $description;
			}
			$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * Term text in the requested language, then the instance's fallback
	 * order, then any remaining language; '' when the item has none.
	 *
	 * @param array<string,string> $terms language code => text
	 * @param string[] $fallback ordered fallback language codes
	 */
	private function termIn( array $terms, string $language, array $fallback ): string {
		if ( isset( $terms[$language] ) ) {
			return $terms[$language];
		}
		foreach ( $fallback as $code ) {
			if ( $code === $language ) {
				continue;
			}
			if ( isset( $terms[$code] ) ) {
				return $terms[$code];
			}
		}
		return $terms === [] ? '' : reset( $terms );
	}

}
