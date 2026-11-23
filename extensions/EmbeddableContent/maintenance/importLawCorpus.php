<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- maintenance entry point

declare( strict_types = 1 );

use EmbeddableContent\Flow\LawItemWriter;
use EmbeddableContent\Law\LawImportException;
use EmbeddableContent\Law\LawImporter;
use EmbeddableContent\Law\LegiParser;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false || $IP === '' ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Import an extracted DILA LEGI tree into wb_law_*.
 *
 *   # Baseline (codes only; the v1 pilot):
 *   php maintenance/run.php .../importLawCorpus.php \
 *     --dir=/var/lib/wikibase/law-corpus/fr/incoming/20261108/extracted \
 *     --mode=baseline
 *
 *   # Daily increment:
 *   php maintenance/run.php .../importLawCorpus.php \
 *     --dir=/var/lib/wikibase/law-corpus/fr/incoming/20261122/extracted \
 *     --mode=increment
 *
 * The archive extraction (`tar -xzf`) is an operational step; this script
 * reads the extracted tree. Legal items are created entity-only (no Source:
 * pages) for the laws seen in the run unless --no-create-items is passed.
 */
class ImportLawCorpus extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Import an extracted DILA LEGI tree into the law-corpus store.' );
		$this->addOption( 'dir', 'Extracted archive root (contains legi/…)', true, true );
		$this->addOption( 'mode', 'baseline|increment', false, true );
		$this->addOption( 'all-legi', 'Import the whole LEGI tree (overrides --codes-only).' );
		$this->addOption( 'limit', 'Stop after N article files (testing).', false, true );
		$this->addOption( 'create-items', 'Create the law entity items (default).' );
		$this->addOption( 'no-create-items', 'Do not create law entity items.' );
		$this->addOption( 'dry-run', 'Parse and count without writing.' );
		$this->addOption( 'user', 'User to attribute item creation to.', false, true );
	}

	public function execute() {
		$dir = (string)$this->getOption( 'dir' );
		$mode = strtolower( (string)( $this->getOption( 'mode' ) ?: 'baseline' ) );
		if ( !in_array( $mode, [ 'baseline', 'increment' ], true ) ) {
			$this->fatalError( 'mode must be baseline or increment' );
		}
		$codesOnly = !$this->getOption( 'all-legi' );
		$createItems = !$this->getOption( 'no-create-items' );
		$limit = (int)( $this->getOption( 'limit' ) ?: 0 );
		$dryRun = (bool)$this->getOption( 'dry-run' );

		$user = null;
		if ( $createItems && !$dryRun ) {
			$user = $this->resolveUser();
		}

		$services = MediaWikiServices::getInstance();
		$store = $services->get( 'EmbeddableContent.LawStore' );
		$writer = $services->get( 'EmbeddableContent.LawItemWriter' );
		$importer = new LawImporter( $store, new LegiParser(), $writer );
		$log = function ( string $message ): void {
			$this->output( $message . "\n" );
		};

		try {
			$stats = $importer->run( $dir, $mode, [
				'codes_only' => $codesOnly,
				'limit' => $limit,
				'create_items' => $createItems,
				'dry_run' => $dryRun,
			], $user, $log );
		} catch ( LawImportException $e ) {
			$this->fatalError( $e->getMessage() );
		}
		$this->output( 'stats: ' . json_encode( $stats, JSON_UNESCAPED_UNICODE ) . "\n" );
	}

	private function resolveUser(): User {
		$name = trim( (string)$this->getOption( 'user' ) );
		if ( $name !== '' ) {
			$user = User::newFromName( $name );
			if ( $user === null || !$user->isRegistered() ) {
				$this->fatalError( "unknown user: $name" );
			}
			return $user;
		}
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		if ( $user === null ) {
			$this->fatalError( 'cannot resolve the Maintenance script user' );
		}
		return $user;
	}
}

$maintClass = ImportLawCorpus::class;
require_once RUN_MAINTENANCE_IF_MAIN;
