<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IExpression;

/**
 * api.php?action=filesearch&search=…&limit=… — CONTAINS file-title search
 * for the "reuse an existing file on this wiki" combobox
 * (resources/fileselect.js), the generator=search replacement.
 *
 * Why not list=search: the wiki's search index matches whole WORD TOKENS of
 * the title/text — typing "astro" finds nothing although
 * "Astronomy and Astrophysics-logo.svg" exists, and a mid-name fragment
 * never matches (both verified on production). This module runs a CONTAINS
 * match (LIKE %term%) over the File namespace's page_title — the page-table
 * analogue of ApiEntitySearch's wbt_* CONTAINS match for the entity
 * comboboxes (same direct-SQL, read-only, in-process deviation from the
 * stable-API rule; the documented upgrade path for a larger instance is
 * CirrusSearch).
 *
 * Separators: whitespace, '_' and '-' in the typed search split into
 * tokens that may match ANY text in between ("european space" finds both
 * "European_Space_Agency-logo.png" and "European-Space-Agency-logo.png"),
 * so a typed human fragment finds the stored DB key. No case variants are
 * needed: page_title uses the page table's collation (case-insensitive
 * utf8mb4 on this instance) — unlike the VARBINARY wbt_text the entity
 * search must vary (T242644).
 *
 * Result shape mirrors the entity search consumers: search[].title, each a
 * "File:…" prefixed title; prefix matches (title starts with the typed
 * term in DB form) rank before contains-only hits.
 *
 * @license GPL-2.0-or-later
 */
class ApiFileSearch extends ApiBase {

	private const MAX_LIMIT = 50;

	public function execute() {
		$params = $this->extractRequestParams();
		$search = trim( (string)$params['search'] );
		if ( $search === '' ) {
			$this->getResult()->addValue( null, 'search', [] );
			return;
		}
		$limit = (int)min( $params['limit'], self::MAX_LIMIT );

		$entries = $this->containsTitles( $search, $limit );

		$this->getResult()->addValue( null, 'search', $entries );
		$this->getResult()->addIndexedTagName( [ 'search' ], 'f' );
	}

	/**
	 * File: titles whose DB key CONTAINS every token of $search (in order,
	 * any separator between them), prefix matches first. Read-only, one
	 * LIKE query over page_title.
	 *
	 * @return array<int,array{title:string}> "File:…" prefixed titles
	 */
	private function containsTitles( string $search, int $limit ): array {
		$tokens = preg_split( '/[\s_-]+/u', $search, -1, PREG_SPLIT_NO_EMPTY );
		if ( !is_array( $tokens ) || $tokens === [] ) {
			return [];
		}

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$pattern = '%' . implode( '%', array_map(
			static fn ( string $token ): string => $dbr->escapeLike( $token ),
			$tokens
		) ) . '%';
		// DB-key prefix form of the typed term for the relevance sort
		// (page_title stores spaces as underscores): "european space" →
		// "european_space".
		$prefixForm = (string)preg_replace( '/[\s_-]+/u', '_', $search );

		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'page_title' ] )
			->from( 'page' )
			->where( [ 'page_namespace' => NS_FILE, 'page_is_redirect' => 0 ] )
			->andWhere( $dbr->expr( 'page_title', IExpression::LIKE, $pattern ) )
			// Overshoot per query: the PHP sort below slices to $limit.
			->limit( $limit * 4 )
			->caller( __METHOD__ )
			->fetchResultSet();

		$titles = [];
		foreach ( $rows as $row ) {
			$titles[] = $row->page_title;
		}
		// Prefix (title starts with the DB-key form of the whole typed
		// term) first, then alphabetical — deterministic suggestion order.
		usort( $titles, static function ( string $a, string $b ) use ( $prefixForm ): int {
			$aPrefix = str_starts_with( strtolower( $a ), strtolower( $prefixForm ) ) ? 0 : 1;
			$bPrefix = str_starts_with( strtolower( $b ), strtolower( $prefixForm ) ) ? 0 : 1;
			return $aPrefix <=> $bPrefix ?: strcasecmp( $a, $b );
		} );

		$entries = [];
		foreach ( array_slice( $titles, 0, $limit ) as $dbKey ) {
			$title = Title::makeTitle( NS_FILE, (string)$dbKey );
			if ( $title === null ) {
				continue;
			}
			$entries[] = [ 'title' => $title->getPrefixedText() ];
		}
		return $entries;
	}

	public function getAllowedParams() {
		return [
			'search' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
				self::PARAM_MAX_BYTES => 100,
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
