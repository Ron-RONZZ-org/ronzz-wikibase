<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;

/**
 * api.php?action=lawget — one law, or one article with its law context.
 *
 * `law` is a law_key (LEGITEXT today, CELEX for the EU v2 importer).
 * `article` is the reference code (e.g. L111-1). Without `article`, the law
 * row and its first 500 article rows are returned.
 *
 * @license GPL-2.0-or-later
 */
class ApiLawGet extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$lawKey = trim( (string)$params['law'] );
		$articleRef = trim( (string)$params['article'] );
		$language = trim( (string)$params['language'] ) ?: 'fr';
		$store = MediaWikiServices::getInstance()->get( 'EmbeddableContent.LawStore' );

		$law = $store->getLaw( $lawKey );
		if ( $law === null ) {
			$this->dieWithError( 'law-not-found', 'law_not_found' );
		}

		$result = [
			'law' => $law,
			'article' => null,
			'articles' => [],
		];
		if ( $articleRef !== '' ) {
			$article = $store->getArticle( $lawKey, $articleRef, $language );
			if ( $article === null ) {
				$this->dieWithError( 'article-not-found', 'article_not_found' );
			}
			$result['article'] = $article;
		} else {
			$result['articles'] = $store->listArticles( $lawKey, 500 );
		}
		$this->getResult()->addValue( null, 'lawget', $result );
	}

	public function getAllowedParams() {
		return [
			'law' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true, self::PARAM_MAX_BYTES => 64 ],
			'article' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => '', self::PARAM_MAX_BYTES => 191 ],
			'language' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => 'fr', self::PARAM_MAX_BYTES => 20 ],
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
		return 'Read a law or one of its articles from the RonzzWikiBase law corpus.';
	}
}
