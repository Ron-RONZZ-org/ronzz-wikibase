<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;

/**
 * api.php?action=lawsearch — search the law corpus.
 *
 * Filters: `q` (law title or article reference), `law` (a law_key), and
 * `jurisdiction`. Returns `laws` and `articles`; an exact `law` + `q` pair
 * narrows the article search to that law.
 *
 * @license GPL-2.0-or-later
 */
class ApiLawSearch extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$q = trim( (string)$params['q'] );
		$lawKey = trim( (string)$params['law'] );
		$jurisdiction = trim( (string)$params['jurisdiction'] );
		$limit = (int)$params['limit'];
		$store = MediaWikiServices::getInstance()->get( 'EmbeddableContent.LawStore' );

		$laws = [];
		$articles = [];
		if ( $q === '' && $lawKey === '' ) {
			$laws = $store->listLaws( $jurisdiction !== '' ? $jurisdiction : null, $limit );
		} elseif ( $q !== '' ) {
			$articles = $store->searchArticles( $q, $lawKey !== '' ? $lawKey : null, $limit );
			if ( $lawKey === '' ) {
				$laws = $store->searchLaws( $q, $jurisdiction !== '' ? $jurisdiction : null, $limit );
			}
		} elseif ( $lawKey !== '' ) {
			$law = $store->getLaw( $lawKey );
			if ( $law !== null ) {
				$laws = [ $law ];
				$articles = $store->listArticles( $lawKey, $limit );
			}
		}

		$this->getResult()->addValue( null, 'lawsearch', [
			'laws' => $laws,
			'articles' => $articles,
			'limit' => $limit,
		] );
	}

	public function getAllowedParams() {
		return [
			'q' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => '', self::PARAM_MAX_BYTES => 500 ],
			'law' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => '', self::PARAM_MAX_BYTES => 64 ],
			'jurisdiction' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => '', self::PARAM_MAX_BYTES => 16 ],
			'limit' => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => 20,
				self::PARAM_MIN => 1, self::PARAM_MAX => 100 ],
		];
	}

	public function isWriteMode() {
		return false;
	}

	public function mustBePosted() {
		return false;
	}

	public function needsToken() {
		return false;
	}

	public function getModuleDescription() {
		return 'Search the RonzzWikiBase law corpus (laws and articles).';
	}
}
