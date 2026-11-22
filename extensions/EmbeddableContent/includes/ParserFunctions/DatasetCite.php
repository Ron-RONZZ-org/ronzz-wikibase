<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use EmbeddableContent\Store\DatasetStore;
use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;

/**
 * `{{#dataset-cite:<dataset_id>}}` — a compact "cite this dataset" block
 * (title, source, year, DOI, page).
 *
 * @license GPL-2.0-or-later
 */
final class DatasetCite {

	public static function onDatasetCite( DatasetStore $store, Parser $parser, array $args ): array {
		$datasetId = trim( (string)( $args[0] ?? '' ) );
		$context = DatasetView::dataset( $store, $parser, $datasetId );
		if ( isset( $context['error'] ) ) {
			return DatasetView::error( $context['error'] );
		}
		$dataset = $context['dataset'];
		$parts = [ htmlspecialchars( (string)$dataset['title'] ) ];
		if ( (string)( $dataset['source_page'] ?? '' ) !== '' ) {
			$source = Title::newFromText( (string)$dataset['source_page'] );
			$parts[] = $source !== null
				? Html::element( 'a', [ 'href' => $source->getLocalURL() ], $source->getPrefixedText() )
				: htmlspecialchars( (string)$dataset['source_page'] );
		}
		if ( (string)( $dataset['year'] ?? '' ) !== '' ) {
			$parts[] = htmlspecialchars( (string)$dataset['year'] );
		}
		if ( (string)( $dataset['doi'] ?? '' ) !== '' ) {
			$doi = htmlspecialchars( (string)$dataset['doi'] );
			$parts[] = Html::rawElement( 'a', [ 'href' => 'https://doi.org/' . rawurlencode( (string)$dataset['doi'] ) ], $doi );
		}
		$page = Title::newFromText( 'Dataset:' . $dataset['dataset_id'] );
		if ( $page !== null ) {
			$parts[] = Html::element( 'a', [ 'href' => $page->getLocalURL() ], 'Dataset:' . $dataset['dataset_id'] );
		}
		return [ Html::rawElement( 'div', [ 'class' => 'wb-dataset-cite' ], implode( '. ', $parts ) ),
			'noparse' => true, 'isHTML' => true ];
	}
}
