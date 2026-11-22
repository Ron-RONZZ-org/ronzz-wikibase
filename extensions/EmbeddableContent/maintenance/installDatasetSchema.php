<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- maintenance entry point

declare( strict_types = 1 );

use EmbeddableContent\Spec\DatasetSchema;
use EmbeddableContent\Spec\DatasetSqlSchema;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false || $IP === '' ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Create the curated-dataset tables (wb_dataset_*) from DatasetSqlSchema.
 *
 *   php maintenance/run.php .../installDatasetSchema.php [--dry-run]
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS). Run once on a fresh instance and
 * after a schema change — the generated DDL is the only source.
 */
class InstallDatasetSchema extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Create the curated-dataset wb_dataset_* tables.' );
		$this->addOption( 'dry-run', 'Print the statements without executing them.' );
	}

	public function execute() {
		$dbw = $this->getDB( DB_PRIMARY );
		$sql = DatasetSqlSchema::sql( $dbw->tablePrefix() . DatasetSchema::TABLE_PREFIX );
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
		$this->output( "dataset schema installed\n" );
	}
}

$maintClass = InstallDatasetSchema::class;
require_once RUN_MAINTENANCE_IF_MAIN;
