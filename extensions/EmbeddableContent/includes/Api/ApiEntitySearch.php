<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\EntityClassFilter;
use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * api.php?action=entitysearch&search=…&language=…&limit=… — FULLTEXT
 * entity search for the entity comboboxes (Special:Add* + Special:Upload),
 * the wbsearchentities replacement.
 *
 * Why not wbsearchentities: this instance's term store (no CirrusSearch)
 * matches labels/aliases EXACT-then-PREFIX — searching "AGPL" never finds
 * "GNU AGPL-3.0" and "Einstein" never finds "Albert Einstein". This module
 * runs a case-INSENSITIVE CONTAINS match (LIKE %term% over
 * CONVERT(wbx_text USING utf8mb4)) over the same wbt_* term tables
 * Wikibase's own DatabaseMatchingTermsLookup reads, in-process and
 * read-only. The term store stores wbx_text as VARBINARY (case-sensitive,
 * upstream T242644); the CONVERT to utf8mb4 gives a case-insensitive
 * collation so "apache"/"APACHE"/"aPaChE" all find "Apache License 2.0"
 * (the old raw/title/upper variant probing only covered a few cases and
 * failed on uppercase and mixed-case input). The same idiom backs
 * ApiFileSearch's page-title CONTAINS match.
 *
 * Result shape mirrors wbsearchentities (the combobox consumers only read
 * `search[].id/label/description`): a label/description is resolved for
 * each hit in the requested language with the configured fallback order.
 *
 * CLASS SCOPING (the `classes` param): a combobox may restrict the search
 * to items that are `instance of` one of a set of classes (pipe-separated
 * item ids, e.g. `classes=Q302` for the license combobox). The term match
 * runs first (it cannot join `instance of` — the claim value is a blob, not
 * a queryable column), then candidates are over-fetched and filtered in
 * PHP. The over-fetch factor is bounded (see CLASS_FILTER_FACTOR /
 * MAX_SCAN); it is the accepted trade-off for a class filter that needs no
 * schema-level index. Scoped searches therefore load more items than
 * unscoped ones — acceptable at this instance's size and documented.
 *
 * The wbt_* schema is Wikibase-internal (stable across 1.4x; the upstream
 * ADR 0027 dropped the wbt_type table, so the label/alias type ids are
 * hardcoded from Wikibase\Lib\Store\Sql\Terms\TermTypeIds rather than
 * joined). Single-source local instance assumed — the tables live in the
 * wiki database (repoDatabase=false).
 *
 * @license GPL-2.0-or-later
 */
class ApiEntitySearch extends ApiBase {

	/** Term-type ids for label + alias (Wikibase TermTypeIds). */
	private const TERM_TYPE_IDS = [ 1, 3 ];

	private const MAX_LIMIT = 50;

	/**
	 * Candidate over-fetch multiplier for a class-scoped search: the term
	 * match runs first, then the class filter drops non-members, so more
	 * candidates are fetched to still fill the caller's limit. Bounded by
	 * MAX_SCAN so a rare class cannot scan unbounded rows.
	 */
	private const CLASS_FILTER_FACTOR = 5;

	/** Hard cap on candidate rows scanned for a class-scoped search. */
	private const MAX_SCAN = 200;

	private EmbeddableContentConfig $config;

	public function __construct(
		$mainModule,
		string $moduleName,
		EmbeddableContentConfig $config
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->config = $config;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$search = trim( (string)$params['search'] );
		if ( $search === '' ) {
			$this->getResult()->addValue( null, 'search', [] );
			return;
		}
		$limit = (int)min( $params['limit'], self::MAX_LIMIT );

		$classIds = EntityClassFilter::parseItemIds( (string)$params['classes'] );
		$fetchLimit = $classIds === []
			? $limit * 2 + 1
			: min( $limit * self::CLASS_FILTER_FACTOR, self::MAX_SCAN ) + 1;

		$rows = $this->containsRows( $search, $fetchLimit );
		$entries = $this->displayEntries( $rows, (string)$params['language'], $limit, $classIds );

		$this->getResult()->addValue( null, 'searchinfo', [ 'search' => $search ] );
		$this->getResult()->addValue( null, 'search', $entries );
		$this->getResult()->addIndexedTagName( [ 'search' ], 'entity' );
	}

	/**
	 * Contains-match rows (item id => matched text) for $search, deduped by
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
	 * @return array<int,array<string,string>>
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

	public function getAllowedParams() {
		return [
			'search' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
				self::PARAM_MAX_BYTES => 100,
			],
			'language' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
				self::PARAM_MAX_BYTES => 20,
			],
			// Pipe-separated item ids: restrict results to items that are
			// `instance of` one of these classes (the combobox scope).
			// Empty/absent = unscoped (the historical behaviour).
			'classes' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_DFLT => '',
				self::PARAM_MAX_BYTES => 2000,
			],
			'limit' => [
				self::PARAM_TYPE => 'limit',
				self::PARAM_DFLT => 10,
				self::PARAM_MIN => 1,
				self::PARAM_MAX => self::MAX_LIMIT,
				self::PARAM_MAX2 => self::MAX_LIMIT,
			],
		];
	}

	public function isWriteMode() {
		return false;
	}

	public function mustBePosted() {
		return false;
	}
}
