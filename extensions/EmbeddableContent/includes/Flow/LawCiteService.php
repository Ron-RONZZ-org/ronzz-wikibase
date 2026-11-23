<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use EmbeddableContent\Law\LawCiteException;
use EmbeddableContent\Store\LawStore;
use MediaWiki\User\UserIdentity;

/**
 * Cite-or-create for the law corpus.
 *
 * The store is the read model; on first citation the law item and the legal
 * provision item are created (entity only, no Source: page) and their ids are
 * written back to the store. Later citations are one row lookup.
 *
 * @license GPL-2.0-or-later
 */
final class LawCiteService {

	private LawStore $store;

	private LawItemWriter $writer;

	public function __construct( LawStore $store, LawItemWriter $writer ) {
		$this->store = $store;
		$this->writer = $writer;
	}

	/**
	 * @return array{qid:string,ref:string,created:bool,law_key:string,reference_code:string}
	 * @throws LawCiteException
	 */
	public function cite( string $lawKey, string $reference, UserIdentity $user ): array {
		$law = $this->store->getLaw( $lawKey );
		if ( $law === null ) {
			throw new LawCiteException( LawCiteException::LAW_NOT_FOUND, "law not found: $lawKey" );
		}
		$article = $this->store->getArticle( $lawKey, $reference );
		if ( $article === null ) {
			throw new LawCiteException( LawCiteException::ARTICLE_NOT_FOUND, "article not found: $lawKey / $reference" );
		}

		$reference = (string)( $article['reference_code'] ?? $reference );

		$created = false;
		$lawItemId = trim( (string)( $law['item_id'] ?? '' ) );
		if ( $lawItemId === '' ) {
			$lawItemId = $this->writer->ensureLawItem( $law, $user ) ?? '';
			if ( $lawItemId === '' ) {
				throw new LawCiteException( LawCiteException::ITEM_CREATE_FAILED, "could not create the law item for $lawKey" );
			}
			$this->store->setLawItemId( $lawKey, $lawItemId );
		}

		$articleItemId = trim( (string)( $article['item_id'] ?? '' ) );
		if ( $articleItemId === '' ) {
			$articleItemId = $this->writer->ensureProvisionItem( $law, $article, $lawItemId, $user ) ?? '';
			if ( $articleItemId === '' ) {
				throw new LawCiteException( LawCiteException::ITEM_CREATE_FAILED, "could not create the provision item for $lawKey / $reference" );
			}
			$this->store->setArticleItemId( $lawKey, $reference, (string)( $article['language'] ?? 'fr' ), $articleItemId );
			$created = true;
		}

		return [
			'qid' => $articleItemId,
			'ref' => '<ref>{{#cite:' . $articleItemId . '}}</ref>',
			'created' => $created,
			'law_key' => $lawKey,
			'reference_code' => $reference,
		];
	}
}
