<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Jobs;

use EmbeddableContent\Pdf\PdfTextExtractor;
use MediaWiki\JobQueue\Job;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

/**
 * Extract one PDF File page to its Markdown companion.
 *
 * Queued on a PDF File page save (Hooks::onPageSaveComplete) and by
 * maintenance/enqueuePdfExtractions.php. The job type is excluded from the
 * default queue ($wgJobTypesExcludedFromDefaultQueue) because OCR can take
 * minutes: a dedicated worker drains it with
 * `runJobs.php --type=extractPdfText --maxjobs=1 --maxtime=...`.
 *
 * A current companion or a non-PDF title acks immediately. An extraction
 * error returns false, so the runner retries the job (maxTries, default 3)
 * instead of dropping it; after the retries the row is pruned, so one
 * unparseable PDF cannot block the queue.
 *
 * @license GPL-2.0-or-later
 */
class ExtractPdfTextJob extends Job {

	public function __construct( $title, array $params = [] ) {
		parent::__construct( 'extractPdfText', $title, $params );
	}

	/** @inheritDoc */
	public function run() {
		$title = $this->getTitle();
		if ( !$title instanceof Title || $title->getNamespace() !== NS_FILE ) {
			return true;
		}
		if ( !PdfTextExtractor::isPdf( $title ) ) {
			return true;
		}
		$force = (bool)( $this->params['force'] ?? false );
		if ( !$force && !PdfTextExtractor::needsExtraction( $title ) ) {
			return true;
		}
		$user = User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] );
		if ( !$user instanceof User ) {
			$this->setLastError( 'No usable maintenance user.' );
			return false;
		}
		$result = PdfTextExtractor::extract(
			$title,
			$user,
			$this->params['source'] ?? null,
			$force
		);
		if ( $result['status'] === 'error' ) {
			$this->setLastError( $result['message'] );
			return false;
		}
		return true;
	}
}
