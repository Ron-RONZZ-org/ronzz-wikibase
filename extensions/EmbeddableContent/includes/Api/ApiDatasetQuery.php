<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;

/**
 * api.php?action=datasetquery — read the measurement store. Applies the
 * dataset access tier (public / registered / internal deny-by-default) and
 * returns JSON rows or a CSV string.
 *
 * @license GPL-2.0-or-later
 */
class ApiDatasetQuery extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$store = MediaWikiServices::getInstance()->get( 'EmbeddableContent.DatasetStore' );
		$userId = $this->getUser();
		$datasetId = (string)( $params['dataset_id'] ?? '' );
		$tableId = (string)( $params['table_id'] ?? '' );
		$datasets = null;
		$rows = [];
		if ( $tableId !== '' ) {
			$table = $store->getTable( $tableId );
			if ( $table === null ) {
				$this->dieWithError( 'not_found' );
			}
			if ( $datasetId !== '' && (string)$table['dataset_id'] !== $datasetId ) {
				$this->dieWithError( 'table_id does not belong to dataset_id' );
			}
			$datasetId = (string)$table['dataset_id'];
		}
		if ( $datasetId !== '' ) {
			$dataset = $store->getDataset( $datasetId );
			if ( $dataset === null ) {
				$this->dieWithError( 'not_found' );
			}
			if ( !$store->canRead( $userId, $dataset ) ) {
				$this->dieWithError( 'permissiondenied' );
			}
			$rows = $store->queryObservations( [
				'dataset_id' => $datasetId,
				'table_id' => $tableId,
				'panel_key' => $params['panel_key'],
				'column_key' => $params['column_key'],
				'search' => $params['search'],
				'order' => $params['order'],
				'limit' => $params['limit'],
				'offset' => $params['offset'],
			] );
		} else {
			$datasets = [];
			foreach ( $store->listDatasets( [
				'search' => $params['search'],
				'limit' => $params['limit'],
				'offset' => $params['offset'],
			] ) as $dataset ) {
				if ( $store->canRead( $userId, $dataset ) ) {
					$datasets[] = $dataset;
				}
			}
		}
		$result = [
			'dataset_id' => $datasetId,
			'table_id' => $tableId,
			'rows' => $rows,
			'limit' => (int)$params['limit'],
			'offset' => (int)$params['offset'],
		];
		if ( $datasets !== null ) {
			$result['datasets'] = $datasets;
		}
		if ( $params['format'] === 'csv' ) {
			$result['csv'] = $this->csv( $datasets !== null ? $datasets : $rows );
		}
		$this->getResult()->addValue( null, 'datasetquery', $result );
	}

	public function getAllowedParams() {
		return [
			'dataset_id' => [ self::PARAM_TYPE => 'string', self::PARAM_MAX_BYTES => 191 ],
			'table_id' => [ self::PARAM_TYPE => 'string', self::PARAM_MAX_BYTES => 191 ],
			'panel_key' => [ self::PARAM_TYPE => 'string', self::PARAM_MAX_BYTES => 64 ],
			'column_key' => [ self::PARAM_TYPE => 'string', self::PARAM_MAX_BYTES => 64 ],
			'search' => [ self::PARAM_TYPE => 'string', self::PARAM_MAX_BYTES => 500 ],
			'order' => [ self::PARAM_TYPE => 'string', self::PARAM_MAX_BYTES => 64 ],
			'limit' => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => 500,
				self::PARAM_MIN => 1, self::PARAM_MAX => 5000 ],
			'offset' => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => 0, self::PARAM_MIN => 0 ],
			'format' => [ self::PARAM_TYPE => [ 'json', 'csv' ], self::PARAM_DFLT => 'json' ],
		];
	}

	public function isWriteMode() {
		return false;
	}

	public function mustBePosted() {
		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function csv( array $rows ): string {
		if ( $rows === [] ) {
			return '';
		}
		$handle = fopen( 'php://temp', 'w+' );
		if ( $handle === false ) {
			return '';
		}
		fputcsv( $handle, array_keys( $rows[0] ) );
		foreach ( $rows as $row ) {
			fputcsv( $handle, array_values( $row ) );
		}
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );
		return $csv === false ? '' : $csv;
	}
}
