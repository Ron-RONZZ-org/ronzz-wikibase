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
 * Upgrade an installed law-corpus schema to LawSchema::VERSION (1.1).
 *
 *   php maintenance/run.php .../upgradeLawSchema.php [--dry-run]
 *
 * Idempotent: creates the tables when missing, adds the 1.1 columns and the
 * `number` index when absent, and rewrites the legacy `source='dila'` rows to
 * `source='legi'`.
 */
class UpgradeLawSchema extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Upgrade the law-corpus schema to the current version.' );
		$this->addOption( 'dry-run', 'Print the statements without executing them.' );
	}

	public function execute() {
		$dbw = $this->getDB( DB_PRIMARY );
		$dry = (bool)$this->getOption( 'dry-run' );
		$prefix = $dbw->tablePrefix() . LawSchema::TABLE_PREFIX;

		// Base tables (idempotent).
		foreach ( preg_split( '/;\s*\n/', LawSqlSchema::sql( $prefix ) ) ?? [] as $statement ) {
			$statement = trim( $statement );
			if ( $statement !== '' ) {
				$this->runStatement( $dbw, $statement, $dry );
			}
		}

		$columns = [
			'laws' => [
				'number' => LawSchema::tables()['laws']['columns']['number'],
				'court' => LawSchema::tables()['laws']['columns']['court'],
			],
			'articles' => [ 'source_url' => LawSchema::tables()['articles']['columns']['source_url'] ],
		];
		foreach ( $columns as $table => $specs ) {
			foreach ( $specs as $column => $definition ) {
				if ( $dbw->fieldExists( $prefix . $table, $column, __METHOD__ ) ) {
					continue;
				}
				$this->runStatement(
					$dbw,
					"ALTER TABLE `{$prefix}{$table}` ADD COLUMN `$column` $definition",
					$dry
				);
			}
		}
		if ( !$dbw->indexExists( $prefix . 'laws', 'i_number', __METHOD__ ) ) {
			$this->runStatement( $dbw, "ALTER TABLE `{$prefix}laws` ADD KEY `i_number` (`number`)", $dry );
		}
		if ( !$dry ) {
			$dbw->update( $prefix . 'laws', [ 'source' => 'legi' ], [ 'source' => 'dila' ], __METHOD__ );
		}
		$this->output( "law schema upgraded\n" );
	}

	private function runStatement( $dbw, string $statement, bool $dry ): void {
		if ( $dry ) {
			$this->output( $statement . ";\n" );
			return;
		}
		$dbw->query( $statement, __METHOD__ );
	}
}

$maintClass = UpgradeLawSchema::class;
require_once RUN_MAINTENANCE_IF_MAIN;
