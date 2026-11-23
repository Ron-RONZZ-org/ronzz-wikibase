<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Law;

use RuntimeException;

/**
 * A citation could not be produced from the corpus (law/article missing, or
 * the entity item could not be created).
 *
 * @license GPL-2.0-or-later
 */
final class LawCiteException extends RuntimeException {

	public const LAW_NOT_FOUND = 'law-not-found';

	public const ARTICLE_NOT_FOUND = 'article-not-found';

	public const ITEM_CREATE_FAILED = 'item-create-failed';

	private string $errorCode;

	public function __construct( string $errorCode, string $message ) {
		parent::__construct( $message );
		$this->errorCode = $errorCode;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}
}
