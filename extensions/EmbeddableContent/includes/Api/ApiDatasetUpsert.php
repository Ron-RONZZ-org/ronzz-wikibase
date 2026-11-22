<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\Jobs\DatasetApplyJob;
use MediaWiki\Api\ApiBase;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Language\RawMessage;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Throwable;

/**
 * api.php?action=datasetupsert — the authoritative validate/apply path for a
 * curated dataset manifest. Validate is synchronous; apply saves the manifest
 * page and queues a datasetApply job (the HTTP request never runs the bulk
 * write).
 *
 * @license GPL-2.0-or-later
 */
class ApiDatasetUpsert extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$user = $this->getUser();
		foreach ( [ 'edit', 'createpage' ] as $right ) {
			if ( !$user->isAllowed( $right ) ) {
				$this->dieWithError( 'permissiondenied' );
			}
		}
		$json = (string)$params['manifest'];
		$manifest = json_decode( $json, true );
		if ( !is_array( $manifest ) ) {
			$this->dieWithError( new RawMessage( 'manifest is not a JSON object' ), 'manifest-invalid' );
		}
		$apply = $params['apply'] || !$params['dryrun'];
		$services = MediaWikiServices::getInstance();
		$validator = $services->get( 'EmbeddableContent.DatasetValidator' );
		$manifest = $validator->normalize( $manifest );
		$report = $validator->validate( $manifest, $apply );
		if ( $apply && $report['status'] === 'errors' ) {
			$this->dieWithError( new RawMessage( $this->firstError( $report ) ), 'manifest-invalid' );
		}
		if ( !$apply ) {
			$this->getResult()->addValue( null, 'datasetupsert', [
				'status' => $report['status'],
				'validationReport' => $report,
				'summary' => $report['summary'],
			] );
			return;
		}

		$store = $services->get( 'EmbeddableContent.DatasetStore' );
		if ( !$store->schemaExists() ) {
			$this->dieWithError(
				new RawMessage( 'the dataset store is not installed; run maintenance/installDatasetSchema.php' ),
				'store-error'
			);
		}
		$datasetId = (string)$manifest['dataset']['dataset_id'];
		$manifestTitle = Title::newFromText( 'Dataset:' . $datasetId . '/manifest', NS_DATASET );
		if ( $manifestTitle === null ) {
			$this->dieWithError( new RawMessage( 'invalid Dataset: title' ), 'manifest-invalid' );
		}
		$page = $services->getWikiPageFactory()->newFromTitle( $manifestTitle );
		$flags = $manifestTitle->exists() ? EDIT_UPDATE : EDIT_NEW;
		$status = $page->doUserEditContent(
			ContentHandler::makeContent( $json, $manifestTitle, CONTENT_MODEL_JSON ),
			$user,
			'dataset manifest: ' . $datasetId
				. ( (string)$params['comment'] !== '' ? ' — ' . $params['comment'] : '' ),
			$flags
		);
		if ( !$status->isOK() ) {
			$this->dieWithError( new RawMessage( $status->getWikiText() ), 'page-conflict' );
		}
		$revision = $status->getValue();
		$revId = is_object( $revision ) && method_exists( $revision, 'getId' ) ? (int)$revision->getId() : 0;
		$runId = $datasetId . '-' . substr( hash( 'sha256', $json ), 0, 12 );
		$store->createRun( $runId, $datasetId );
		try {
			$job = new DatasetApplyJob( $manifestTitle, [
				'runId' => $runId,
				'datasetId' => $datasetId,
				'manifestRevision' => $revId,
				'user' => $user->getName(),
			] );
			$services->getJobQueueGroupFactory()->makeJobQueueGroup()->push( $job );
		} catch ( Throwable $e ) {
			$store->updateRun( $runId, [ 'status' => 'failed', 'message' => $e->getMessage() ] );
			$this->dieWithError( new RawMessage( 'could not queue the apply job' ), 'store-error' );
		}
		$this->getResult()->addValue( null, 'datasetupsert', [
			'status' => 'queued',
			'runId' => $runId,
			'manifestTitle' => $manifestTitle->getPrefixedText(),
			'manifestRevision' => $revId,
			'validationReport' => $report,
		] );
	}

	public function getAllowedParams() {
		return [
			'manifest' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
				self::PARAM_MAX_BYTES => 1048576,
			],
			'dryrun' => [ self::PARAM_TYPE => 'boolean', self::PARAM_DFLT => false ],
			'apply' => [ self::PARAM_TYPE => 'boolean', self::PARAM_DFLT => false ],
			'comment' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => '' ],
		];
	}

	public function needsToken() {
		return 'csrf';
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	private function firstError( array $report ): string {
		foreach ( $report['checks'] as $check ) {
			if ( $check['severity'] === 'error' ) {
				return $check['path'] . ': ' . $check['message'];
			}
		}
		return 'manifest validation failed';
	}
}
