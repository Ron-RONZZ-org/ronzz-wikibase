<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- maintenance entry point

declare( strict_types = 1 );

use EmbeddableContent\Pdf\PdfTextExtractor;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false || $IP === '' ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Extract PDF → Markdown via Apache Tika and link it to the Source(s).
 *
 * Default: inspect the newest $scan PDFs and extract up to $limit that are
 * missing/stale. Run it from a dedicated cron (Tika OCR can take minutes; do
 * NOT let it share the general 5-minute runJobs cycle):
 *
 *   every 10 minutes: php maintenance/run.php .../extractPdfText.php --scan=50 --limit=1
 *
 * Manual:
 *   --file=File:Foo.pdf     one file
 *   --all                   re-extract every PDF inspected (implies --force)
 *   --force                 re-extract even if the companion is current
 *   --source=Q42            link to this Source explicitly (skip WDQS)
 *   --scan=N / --limit=N
 *   --dry-run
 */
class ExtractPdfText extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Extract PDFs to Markdown with Apache Tika (PDF → .md companion + Source link).' );
		$this->addOption( 'file', 'A single File: title', false, true );
		$this->addOption( 'source', 'Source item id to link explicitly (Q…)', false, true );
		$this->addOption( 'scan', 'PDFs to inspect in the backfill scan (default 50)', false, true );
		$this->addOption( 'limit', 'Extractions to perform (0 = unlimited; default 1)', false, true );
		$this->addOption( 'all', 'Re-extract every inspected PDF' );
		$this->addOption( 'force', 'Re-extract even when the companion is current' );
		$this->addOption( 'dry-run', 'Print what would be done, no writes' );
	}

	public function execute() {
		$user = User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] );
		if ( !$user instanceof User ) {
			$this->fatalError( 'No usable maintenance user.' );
		}
		$source = $this->getOption( 'source' );
		$force = (bool)$this->hasOption( 'force' ) || (bool)$this->hasOption( 'all' );
		$dryRun = (bool)$this->hasOption( 'dry-run' );
		$limit = max( 0, (int)( $this->getOption( 'limit' ) ?? 1 ) );
		$scan = max( 1, (int)( $this->getOption( 'scan' ) ?? 50 ) );

		$file = $this->getOption( 'file' );
		if ( $file !== null && $file !== '' ) {
			$title = Title::newFromText( (string)$file, NS_FILE );
			if ( $title === null || $title->getNamespace() !== NS_FILE ) {
				$this->fatalError( "Invalid File: title: {$file}" );
			}
			$titles = [ $title ];
		} else {
			$titles = PdfTextExtractor::pdfTitles( $scan );
		}

		$done = 0;
		foreach ( $titles as $title ) {
			if ( !PdfTextExtractor::isPdf( $title ) ) {
				continue;
			}
			if ( !$force && !PdfTextExtractor::needsExtraction( $title ) ) {
				continue;
			}
			if ( $dryRun ) {
				$this->output( "would extract {$title->getPrefixedText()}\n" );
				continue;
			}
			$result = PdfTextExtractor::extract( $title, $user, $source, $force );
			$this->output( sprintf(
				"%s: %s (%d chars%s) — %s\n",
				$title->getPrefixedText(),
				$result['status'],
				$result['chars'],
				$result['partial'] ? ', partial' : '',
				$result['message']
			) );
			if ( $result['status'] === 'ok' ) {
				$done++;
				if ( $limit > 0 && $done >= $limit ) {
					break;
				}
			}
		}
		if ( $done === 0 && !$dryRun ) {
			$this->output( "no PDF needed extraction\n" );
		}
		return true;
	}
}

$maintClass = ExtractPdfText::class;
require_once RUN_MAINTENANCE_IF_MAIN;
