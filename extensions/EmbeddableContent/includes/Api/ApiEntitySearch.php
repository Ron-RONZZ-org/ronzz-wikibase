<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EntityClassFilter;
use EmbeddableContent\Search\EntityTermSearch;
use MediaWiki\Api\ApiBase;

/**
 * api.php?action=entitysearch&search=…&language=…&limit=… — FULLTEXT
 * entity search for the entity comboboxes (Special:Add* + Special:Upload),
 * the wbsearchentities replacement.
 *
 * The CONTAINS matching itself lives in Search\EntityTermSearch (shared
 * with the Special:Search "Items" section); this module only parses and
 * validates the request and shapes the wbsearchentities-compatible result
 * (`search[].id/label/description`).
 *
 * CLASS SCOPING (the `classes` param): a combobox may restrict the search
 * to items that are `instance of` one of a set of classes (pipe-separated
 * item ids, e.g. `classes=Q302` for the license combobox). The term match
 * runs first (it cannot join `instance of` — the claim value is a blob, not
 * a queryable column), then candidates are over-fetched and filtered in
 * PHP by EntityTermSearch.
 *
 * @license GPL-2.0-or-later
 */
class ApiEntitySearch extends ApiBase {

	private const MAX_LIMIT = 50;

	private EntityTermSearch $search;

	public function __construct(
		$mainModule,
		string $moduleName,
		EntityTermSearch $search
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->search = $search;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$term = trim( (string)$params['search'] );
		if ( $term === '' ) {
			$this->getResult()->addValue( null, 'search', [] );
			return;
		}
		$limit = (int)min( $params['limit'], self::MAX_LIMIT );

		$classIds = EntityClassFilter::parseItemIds( (string)$params['classes'] );
		$entries = $this->search->search( $term, (string)$params['language'], $limit, $classIds );

		$this->getResult()->addValue( null, 'searchinfo', [ 'search' => $term ] );
		$this->getResult()->addValue( null, 'search', $entries );
		$this->getResult()->addIndexedTagName( [ 'search' ], 'entity' );
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
