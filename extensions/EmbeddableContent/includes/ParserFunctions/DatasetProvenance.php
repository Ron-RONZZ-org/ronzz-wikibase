<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use EmbeddableContent\Store\DatasetStore;
use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;

/**
 * `{{#dataset-provenance:<table_id>}}` — the source/page/PDF/method/review
 * line under a table.
 *
 * @license GPL-2.0-or-later
 */
final class DatasetProvenance {

	public static function onDatasetProvenance( DatasetStore $store, Parser $parser, array $args ): array {
		$tableId = trim( (string)( $args[0] ?? '' ) );
		$context = DatasetView::table( $store, $parser, $tableId );
		if ( isset( $context['error'] ) ) {
			return DatasetView::error( $context['error'] );
		}
		$table = $context['table'];
		$parts = [];
		if ( (string)( $table['pdf_file'] ?? '' ) !== '' ) {
			$file = Title::newFromText( (string)$table['pdf_file'], NS_FILE );
			$parts[] = $file !== null
				? Html::element( 'a', [ 'href' => $file->getLocalURL() ], $file->getText() )
				: htmlspecialchars( (string)$table['pdf_file'] );
		}
		if ( (string)( $table['page'] ?? '' ) !== '' ) {
			$parts[] = 'page ' . htmlspecialchars( (string)$table['page'] );
		}
		$parts[] = 'method: ' . htmlspecialchars( (string)( $table['method'] ?? 'manual' ) );
		if ( ( $table['confidence'] ?? null ) !== null ) {
			$parts[] = 'confidence: ' . htmlspecialchars( number_format( (float)$table['confidence'], 2 ) );
		}
		$parts[] = 'review: ' . htmlspecialchars( (string)( $table['review_status'] ?? 'unreviewed' ) );
		return [ Html::rawElement( 'div', [ 'class' => 'wb-dataset-provenance' ],
			implode( ' · ', $parts ) ), 'noparse' => true, 'isHTML' => true ];
	}
}
