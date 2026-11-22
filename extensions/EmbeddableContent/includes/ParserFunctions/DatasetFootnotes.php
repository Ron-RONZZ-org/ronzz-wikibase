<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use EmbeddableContent\Store\DatasetStore;
use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;

/**
 * `{{#dataset-footnotes:<table_id>}}` — the notes list with a backlink to the
 * table.
 *
 * @license GPL-2.0-or-later
 */
final class DatasetFootnotes {

	public static function onDatasetFootnotes( DatasetStore $store, Parser $parser, array $args ): array {
		$tableId = trim( (string)( $args[0] ?? '' ) );
		$context = DatasetView::table( $store, $parser, $tableId );
		if ( isset( $context['error'] ) ) {
			return DatasetView::error( $context['error'] );
		}
		$footnotes = $store->getFootnotes( $tableId );
		if ( $footnotes === [] ) {
			return [ '', 'noparse' => true ];
		}
		$items = '';
		foreach ( $footnotes as $footnote ) {
			$text = htmlspecialchars( (string)$footnote['text'] );
			$link = Html::element( 'a', [ 'href' => '#table-' . $tableId ], '↩' );
			$items .= Html::rawElement( 'li',
				[ 'id' => 'fn-' . $tableId . '-' . $footnote['footnote_key'] ],
				$text . ' ' . $link );
		}
		return [ Html::rawElement( 'ol', [ 'class' => 'wb-dataset-footnotes' ], $items ),
			'noparse' => true, 'isHTML' => true ];
	}
}
