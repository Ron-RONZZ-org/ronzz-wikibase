<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\Law\LawCiteException;
use MediaWiki\Api\ApiBase;
use MediaWiki\Language\RawMessage;
use MediaWiki\MediaWikiServices;

/**
 * api.php?action=lawcite — cite-or-create one corpus article.
 *
 * The store is the source of the text; on first citation the law and the
 * legal-provision entity items are created (no Source: page) and the ids are
 * written back. Repeat calls return the same item.
 *
 * @license GPL-2.0-or-later
 */
class ApiLawCite extends ApiBase {

	public function execute() {
		if ( !$this->getUser()->isAllowed( 'edit' ) ) {
			$this->dieWithError( 'permissiondenied', 'permission_denied' );
		}
		$params = $this->extractRequestParams();
		$lawKey = trim( (string)$params['law'] );
		$articleRef = trim( (string)$params['article'] );
		try {
			$result = MediaWikiServices::getInstance()->get( 'EmbeddableContent.LawCiteService' )
				->cite( $lawKey, $articleRef, $this->getUser() );
		} catch ( LawCiteException $e ) {
			$this->dieWithError(
				new RawMessage( $e->getErrorCode() . ': ' . $e->getMessage() ),
				$e->getErrorCode()
			);
		}
		$this->getResult()->addValue( null, 'lawcite', $result );
	}

	public function getAllowedParams() {
		return [
			'law' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true, self::PARAM_MAX_BYTES => 64 ],
			'article' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true, self::PARAM_MAX_BYTES => 191 ],
		];
	}

	public function isWriteMode() {
		return true;
	}

	public function mustBePosted() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function getModuleDescription() {
		return 'Create or reuse the citable entity item for a law-corpus article.';
	}
}
