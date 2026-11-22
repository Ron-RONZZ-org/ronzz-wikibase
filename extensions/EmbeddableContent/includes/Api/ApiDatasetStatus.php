<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;

/**
 * api.php?action=datasetstatus — poll a queued/running datasetApply run.
 *
 * @license GPL-2.0-or-later
 */
class ApiDatasetStatus extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$store = MediaWikiServices::getInstance()->get( 'EmbeddableContent.DatasetStore' );
		$run = $store->getRun( (string)$params['run_id'] );
		if ( $run === null ) {
			$this->dieWithError( 'not_found' );
		}
		$dataset = $store->getDataset( (string)$run['dataset_id'] );
		if ( $dataset !== null && !$store->canRead( $this->getUser(), $dataset ) ) {
			$this->dieWithError( 'permissiondenied' );
		}
		$result = $run;
		$result['row_counts'] = $this->decode( $run['row_counts'] ?? null );
		$result['report_json'] = $this->decode( $run['report_json'] ?? null );
		$this->getResult()->addValue( null, 'datasetstatus', $result );
	}

	public function getAllowedParams() {
		return [
			'run_id' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true,
				self::PARAM_MAX_BYTES => 191 ],
		];
	}

	public function isWriteMode() {
		return false;
	}

	public function mustBePosted() {
		return false;
	}

	private function decode( $value ) {
		if ( !is_string( $value ) || $value === '' ) {
			return null;
		}
		$decoded = json_decode( $value, true );
		return $decoded === null ? $value : $decoded;
	}
}
