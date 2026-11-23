<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- maintenance entry point

declare( strict_types = 1 );

use EmbeddableContent\Spec\LawSchema;
use EmbeddableContent\Spec\LawSqlSchema;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false || $IP === '' ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Create the law-corpus tables (wb_law_*) from LawSqlSchema.
 *
 *   php maintenance/run.php .../installLawSchema.php [--dry-run]
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS). Run once on a fresh instance and
 * after a schema change.
 */
class InstallLawSchema extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Create the law-corpus wb_law_* tables.' );
		$this->addOption( 'dry-run', 'Print the statements without executing them.' );
	}

	public function execute() {
		$dbw = $this->getDB( DB_PRIMARY );
		$sql = LawSqlSchema::sql( $dbw->tablePrefix() . LawSchema::TABLE_PREFIX );
		$statements = preg_split( '/;\s*\n/', $sql );
		$dry = (bool)$this->getOption( 'dry-run' );
		foreach ( $statements ?? [] as $statement ) {
			$statement = trim( $statement );
			if ( $statement === '' ) {
				continue;
			}
			if ( $dry ) {
				$this->output( $statement . ";\n" );
				continue;
			}
			$dbw->query( $statement, __METHOD__ );
		}
		$this->output( "law schema installed\n" );
	}
}

$maintClass = InstallLawSchema::class;
require_once RUN_MAINTENANCE_IF_MAIN;
