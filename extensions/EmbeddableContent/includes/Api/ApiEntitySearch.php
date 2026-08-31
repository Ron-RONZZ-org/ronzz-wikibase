<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
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
 * runs a CONTAINS match (LIKE %term%) over the same wbt_* term tables
 * Wikibase's own DatabaseMatchingTermsLookup reads, in-process and
 * read-only. It queries the raw + title-cased + uppercase variants of the
 * typed text because the instance's term store is case-sensitive
 * (VARBINARY wbx_text — upstream T242644), then merges the deduped hits.
 *
 * Result shape mirrors wbsearchentities (the combobox consumers only read
 * `search[].id/label/description`): a label/description is resolved for
 * each hit in the requested language with the configured fallback order.
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

		$rows = $this->containsRows( $search, $limit );
		$entries = $this->displayEntries( $rows, (string)$params['language'], $limit );

		$this->getResult()->addValue( null, 'searchinfo', [ 'search' => $search ] );
		$this->getResult()->addValue( null, 'search', $entries );
		$this->getResult()->addIndexedTagName( [ 'search' ], 'entity' );
	}

	/**
	 * Contains-match rows (item id => matched text) for the case variants
	 * of $search, deduped by item id. Each variant runs the same
	 * LIKE %term% query Wikibase's DatabaseMatchingTermsLookup would build
	 * for a prefix — only the term is wrapped in leading wildcards.
	 *
	 * @return array<string,string> item id => first matched text
	 */
	private function containsRows( string $search, int $limit ): array {
		$services = MediaWikiServices::getInstance();
		$source = WikibaseRepo::getLocalEntitySource();
		$database = $source->getDatabaseName();
		$dbr = $services->getDBLoadBalancer()->getConnection(
			DB_REPLICA,
			[],
			$database !== false ? $database : null
		);

		$variants = [ $search ];
		$tc = preg_replace_callback(
			'/(^|\s)(\S)/u',
			static fn ( array $m ) => $m[1] . mb_strtoupper( $m[2] ),
			$search
		);
		$up = mb_strtoupper( $search );
		if ( $tc !== $search && $tc !== $up ) {
			$variants[] = $tc;
		}
		if ( $up !== $search && !in_array( $up, $variants, true ) ) {
			$variants[] = $up;
		}

		$rows = [];
		foreach ( $variants as $variant ) {
			// Per-variant query cap: the merged, deduped result is capped
			// again at $limit by the caller, so each variant may overshoot.
			$queryBuilder = $dbr->newSelectQueryBuilder()
				->select( [ 'wbit_item_id', 'wbx_text' ] )
				->from( 'wbt_item_terms' )
				->join( 'wbt_term_in_lang', null, 'wbit_term_in_lang_id=wbtl_id' )
				->join( 'wbt_text_in_lang', null, 'wbtl_text_in_lang_id=wbxl_id' )
				->join( 'wbt_text', null, 'wbxl_text_id=wbx_id' )
				->where( $dbr->expr(
					'wbx_text',
					IExpression::LIKE,
					new LikeValue( $dbr->anyString(), $variant, $dbr->anyString() )
				) )
				->where( [ 'wbtl_type_id' => self::TERM_TYPE_IDS ] )
				->limit( $limit * 2 + 1 );
			foreach ( $queryBuilder->caller( __METHOD__ )->fetchResultSet() as $row ) {
				if ( !isset( $rows[$row->wbit_item_id] ) ) {
					$rows[$row->wbit_item_id] = $row->wbx_text;
				}
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
	 * @return array<int,array<string,string>>
	 */
	private function displayEntries( array $rows, string $language, int $limit ): array {
		$lookup = WikibaseRepo::getEntityLookup();
		$fallback = $this->config->fallbackLanguages();
		$entries = [];
		foreach ( array_slice( array_keys( $rows ), 0, $limit, true ) as $numericId ) {
			$item = $lookup->getEntity( new ItemId( 'Q' . $numericId ) );
			if ( !$item instanceof Item ) {
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
