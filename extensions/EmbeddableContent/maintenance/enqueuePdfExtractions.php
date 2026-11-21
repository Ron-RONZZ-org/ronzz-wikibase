<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- maintenance entry point

declare( strict_types = 1 );

use EmbeddableContent\Pdf\PdfTextExtractor;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false || $IP === '' ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Enqueue extractPdfText jobs for PDFs that have no current companion.
 *
 * The queue producer is the PDF File page save hook, so this script is the
 * backfill for files uploaded before the queue existed (and the repair path
 * after a restore). It never extracts inline: the dedicated worker drains
 * the jobs, one at a time.
 *
 *   php maintenance/run.php extensions/EmbeddableContent/maintenance/enqueuePdfExtractions.php --scan=500
 *   ... --dry-run
 *   ... --force        (enqueue even when the companion is current)
 *
 * @license GPL-2.0-or-later
 */
class EnqueuePdfExtractions extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Enqueue extractPdfText jobs for PDFs missing a current Markdown companion.' );
		$this->addOption( 'scan', 'PDFs to inspect (default 500)', false, true );
		$this->addOption( 'force', 'Enqueue even when the companion is current' );
		$this->addOption( 'dry-run', 'Print the plan without enqueueing' );
	}

	public function execute() {
		$scan = max( 1, (int)( $this->getOption( 'scan' ) ?? 500 ) );
		$force = (bool)$this->hasOption( 'force' );
		$dryRun = (bool)$this->hasOption( 'dry-run' );
		$group = MediaWikiServices::getInstance()
			->getJobQueueGroupFactory()
			->makeJobQueueGroup();

		$queued = 0;
		foreach ( PdfTextExtractor::pdfTitles( $scan ) as $title ) {
			if ( !PdfTextExtractor::isPdf( $title ) ) {
				continue;
			}
			if ( !$force && !PdfTextExtractor::needsExtraction( $title ) ) {
				continue;
			}
			$queued++;
			if ( $dryRun ) {
				$this->output( "would enqueue {$title->getPrefixedText()}\n" );
				continue;
			}
			$group->push( new JobSpecification(
				'extractPdfText',
				[ 'force' => $force ],
				[ 'removeDuplicates' => true ],
				$title
			) );
			$this->output( "enqueued {$title->getPrefixedText()}\n" );
		}
		$this->output( ( $dryRun ? 'would enqueue ' : 'enqueued ' ) . $queued . " job(s)\n" );
		return true;
	}
}

$maintClass = EnqueuePdfExtractions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
