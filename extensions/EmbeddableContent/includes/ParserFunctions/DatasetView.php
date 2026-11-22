<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use EmbeddableContent\Store\DatasetStore;
use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;

/**
 * Shared access + parser-cache dependency handling for the #dataset-*
 * functions. Non-public tiers disable the parser cache (the output depends on
 * the reader); every function records the Dataset: page as a template
 * dependency so a re-apply purges transcluding articles.
 *
 * @license GPL-2.0-or-later
 */
final class DatasetView {

	/**
	 * @return array{table?:array<string,mixed>,dataset?:array<string,mixed>,error?:string}
	 */
	public static function table( DatasetStore $store, Parser $parser, string $tableId ): array {
		$table = $store->getTable( $tableId );
		if ( $table === null ) {
			return [ 'error' => "unknown dataset table $tableId" ];
		}
		$dataset = $store->getDataset( (string)$table['dataset_id'] );
		if ( $dataset === null ) {
			return [ 'error' => "unknown dataset {$table['dataset_id']}" ];
		}
		return self::check( $store, $parser, $dataset, $table );
	}

	/**
	 * @return array{dataset?:array<string,mixed>,error?:string}
	 */
	public static function dataset( DatasetStore $store, Parser $parser, string $datasetId ): array {
		$dataset = $store->getDataset( $datasetId );
		if ( $dataset === null ) {
			return [ 'error' => "unknown dataset $datasetId" ];
		}
		return self::check( $store, $parser, $dataset );
	}

	/**
	 * @return array{table?:array<string,mixed>,dataset?:array<string,mixed>,error?:string}
	 */
	private static function check( DatasetStore $store, Parser $parser, array $dataset, ?array $table = null ): array {
		$title = Title::newFromText( 'Dataset:' . $dataset['dataset_id'] );
		if ( $title !== null ) {
			$parser->getOutput()->addTemplate( $title, $title->getArticleID(), $title->getLatestRevID() );
		}
		if ( (string)( $dataset['access_tier'] ?? 'public' ) !== 'public' ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
		}
		if ( !$store->canRead( RequestContext::getMain()->getUser(), $dataset ) ) {
			return [ 'error' => wfMessage( 'embeddablecontent-dataset-access-denied' )->text() ];
		}
		return $table === null ? [ 'dataset' => $dataset ] : [ 'dataset' => $dataset, 'table' => $table ];
	}

	public static function error( string $message ): array {
		return [ Html::element( 'span', [ 'class' => 'error' ], $message ), 'noparse' => true, 'isHTML' => true ];
	}
}
