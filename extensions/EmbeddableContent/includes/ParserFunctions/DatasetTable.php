<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use EmbeddableContent\Store\DatasetStore;
use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use MediaWiki\Context\RequestContext;

/**
 * `{{#dataset-table:<table_id>|panel=<key>}}` — renders one data table from
 * the measurement store: a leading row label, one column per data column, and
 * linked footnote superscripts.
 *
 * @license GPL-2.0-or-later
 */
final class DatasetTable {

	public static function onDatasetTable( DatasetStore $store, Parser $parser, array $args ): array {
		$tableId = trim( (string)( $args[0] ?? '' ) );
		$panel = null;
		foreach ( $args as $arg ) {
			if ( preg_match( '/^\s*panel\s*=\s*(.+)$/', (string)$arg, $m ) ) {
				$panel = trim( $m[1] );
			}
		}
		if ( $tableId === '' ) {
			return self::error( 'dataset table id is required' );
		}
		$table = $store->getTable( $tableId );
		if ( $table === null ) {
			return self::error( "unknown dataset table $tableId" );
		}
		$dataset = $store->getDataset( (string)$table['dataset_id'] );
		if ( $dataset === null ) {
			return self::error( "unknown dataset {$table['dataset_id']}" );
		}
		$user = RequestContext::getMain()->getUser();
		self::registerDependency( $parser, $dataset );
		if ( !$store->canRead( $user, $dataset ) ) {
			return self::error( wfMessage( 'embeddablecontent-dataset-access-denied' )->text() );
		}
		$columns = $store->getColumns( $tableId, $panel );
		$observations = $store->getObservations( $tableId, $panel );
		$footnotes = [];
		foreach ( $store->getFootnotes( $tableId ) as $footnote ) {
			$footnotes[(string)$footnote['footnote_key']] = (string)( $footnote['marker_raw'] ?? $footnote['footnote_key'] );
		}

		$rows = [];
		foreach ( $observations as $obs ) {
			$rows[(int)$obs['row_index']][] = $obs;
		}
		$header = Html::rawElement( 'tr', [],
			Html::element( 'th', [], $table['caption'] !== '' ? 'Row' : '' )
			. implode( '', array_map( static function ( array $column ): string {
				$label = htmlspecialchars( (string)$column['label'] );
				if ( (string)( $column['unit'] ?? '' ) !== '' ) {
					$label .= ' <small>' . htmlspecialchars( (string)$column['unit'] ) . '</small>';
				}
				return Html::rawElement( 'th', [], $label );
			}, $columns ) ) );
		$body = '';
		foreach ( $rows as $rowIndex => $cells ) {
			$label = (string)( $cells[0]['row_label'] ?? '' );
			$row = Html::element( 'th', [ 'scope' => 'row' ], $label );
			foreach ( $columns as $column ) {
				$cell = null;
				foreach ( $cells as $candidate ) {
					if ( (string)$candidate['column_key'] === (string)$column['column_key'] ) {
						$cell = $candidate;
						break;
					}
				}
				$row .= self::cell( $tableId, $rowIndex, $column, $cell, $footnotes );
			}
			$body .= Html::rawElement( 'tr', [], $row );
		}
		$html = Html::rawElement( 'div', [ 'class' => 'wb-dataset-table', 'id' => 'table-' . $tableId ],
			Html::rawElement( 'table', [ 'class' => 'wikitable' ], $header . $body ) );
		return [ $html, 'noparse' => true, 'isHTML' => true ];
	}

	/**
	 * @param array<string,mixed>|null $cell
	 * @param array<string,string> $footnotes
	 */
	private static function cell(
		string $tableId,
		int $rowIndex,
		array $column,
		?array $cell,
		array $footnotes
	): string {
		$value = $cell === null ? (string)( $column['missing_token'] ?? '' ) : self::value( $cell );
		$cellId = 'fnref-' . $tableId . '-' . $rowIndex . '-' . $column['column_key'];
		$sup = '';
		foreach ( explode( ',', (string)( $cell['footnote_keys'] ?? '' ) ) as $key ) {
			$key = trim( $key );
			if ( $key === '' ) {
				continue;
			}
			$marker = $footnotes[$key] ?? $key;
			$sup .= Html::rawElement( 'sup', [],
				Html::element( 'a', [ 'href' => '#fn-' . $tableId . '-' . $key ], $marker ) );
		}
		return Html::rawElement( 'td', [ 'id' => $cellId ],
			htmlspecialchars( $value ) . $sup );
	}

	private static function value( array $cell ): string {
		if ( (string)( $cell['value_text'] ?? '' ) !== '' ) {
			return (string)$cell['value_text'];
		}
		foreach ( [ 'value_float', 'value_int', 'value_date' ] as $key ) {
			if ( ( $cell[$key] ?? null ) !== null ) {
				return (string)$cell[$key];
			}
		}
		return '';
	}

	private static function registerDependency( Parser $parser, array $dataset ): void {
		$title = Title::newFromText( 'Dataset:' . $dataset['dataset_id'] );
		if ( $title !== null ) {
			$parser->getOutput()->addTemplate( $title, $title->getArticleID(), $title->getLatestRevID() );
		}
		if ( (string)( $dataset['access_tier'] ?? 'public' ) !== 'public' ) {
			// The output depends on the reader; do not share the parser cache.
			$parser->getOutput()->updateCacheExpiry( 0 );
		}
	}

	private static function error( string $message ): array {
		return [ Html::element( 'span', [ 'class' => 'error' ], $message ), 'noparse' => true, 'isHTML' => true ];
	}
}
