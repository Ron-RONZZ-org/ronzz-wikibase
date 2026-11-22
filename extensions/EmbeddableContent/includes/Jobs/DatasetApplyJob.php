<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Jobs;

use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Throwable;

/**
 * Applies a saved dataset manifest: validate, store rows, write Wikibase
 * items, render/save the view page, verify. The API enqueues one job per
 * manifest revision; the run row is the status surface for
 * action=datasetstatus.
 *
 * @license GPL-2.0-or-later
 */
class DatasetApplyJob extends Job {

	public function __construct( Title $title, array $params = [] ) {
		parent::__construct( 'datasetApply', $title, $params );
	}

	public function run() {
		$services = MediaWikiServices::getInstance();
		$store = $services->get( 'EmbeddableContent.DatasetStore' );
		$runId = (string)( $this->params['runId'] ?? '' );
		if ( $runId === '' ) {
			return false;
		}
		$store->updateRun( $runId, [ 'status' => 'running', 'step' => 'read' ] );
		try {
			$revision = $services->getRevisionStore()->getRevisionById(
				(int)( $this->params['manifestRevision'] ?? 0 )
			);
			if ( $revision === null ) {
				throw new \RuntimeException( 'manifest revision is missing' );
			}
			$content = $revision->getContent( SlotRecord::MAIN );
			$json = $content !== null ? $content->getText() : '';
			$manifest = json_decode( $json, true );
			if ( !is_array( $manifest ) ) {
				throw new \RuntimeException( 'manifest page is not a JSON object' );
			}
			$validator = $services->get( 'EmbeddableContent.DatasetValidator' );
			$report = $validator->validate( $manifest, true );
			if ( $report['status'] === 'errors' ) {
				$store->updateRun( $runId, [
					'status' => 'failed', 'step' => 'validate',
					'message' => 'manifest validation failed',
					'report_json' => json_encode( $report, JSON_UNESCAPED_SLASHES ),
				] );
				return false;
			}
			$manifest = $validator->normalize( $manifest );
			$user = User::newFromName( (string)( $this->params['user'] ?? '' ) );
			if ( !$user instanceof User || !$user->isRegistered() ) {
				throw new \RuntimeException( 'the applying user no longer exists' );
			}
			$hash = hash( 'sha256', $json );
			$service = $services->get( 'EmbeddableContent.DatasetApplyService' );
			$service->apply( $manifest, $hash, $revision->getId(), $runId, $user, $report );
			return true;
		} catch ( Throwable $e ) {
			$store->updateRun( $runId, [
				'status' => 'failed', 'step' => 'error',
				'message' => $e->getMessage(),
			] );
			wfLogWarning( 'datasetApply ' . $runId . ' failed: ' . $e->getMessage() );
			return false;
		}
	}

	public function allowRetries() {
		return false;
	}
}
