<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * The MySQL DDL for the curated-dataset store.
 *
 * Split out of DatasetSchema (the manifest contract) so each file stays within
 * the repo's size guideline; DatasetSchema::sql() delegates here. The tables
 * are wb_dataset_-prefixed in the existing MediaWiki database for v1.
 *
 * @license GPL-2.0-or-later
 */
final class DatasetSqlSchema {

	/**
	 * Builds the DDL for every table. The caller connects to the target DB.
	 */
	public static function sql( string $prefix = DatasetSchema::TABLE_PREFIX ): string {
		$out = "-- AUTO-GENERATED — do not edit by hand.\n"
			. "-- Source: extensions/EmbeddableContent/includes/Spec/DatasetSchema.php\n"
			. "-- Regenerate: php extensions/EmbeddableContent/maintenance/emitDatasetContract.php\n\n";
		foreach ( self::tables() as $name => $table ) {
			$lines = [];
			foreach ( $table['columns'] as $column => $definition ) {
				$lines[] = "  `$column` $definition";
			}
			$lines[] = '  PRIMARY KEY (`' . $table['primary'] . '`)';
			foreach ( $table['unique'] ?? [] as $cols ) {
				$lines[] = '  UNIQUE KEY `u_' . implode( '_', $cols ) . '` (`' . implode( '`, `', $cols ) . '`)';
			}
			foreach ( $table['indexes'] ?? [] as $cols ) {
				$lines[] = '  KEY `i_' . implode( '_', $cols ) . '` (`' . implode( '`, `', $cols ) . '`)';
			}
			foreach ( $table['fulltext'] ?? [] as $col ) {
				$lines[] = "  FULLTEXT KEY `ft_$col` (`$col`)";
			}
			foreach ( $table['foreign'] ?? [] as $fk ) {
				$lines[] = "  CONSTRAINT `fk_{$name}_{$fk[0]}` FOREIGN KEY (`{$fk[0]}`) REFERENCES `"
					. $prefix . "{$fk[1]}` (`{$fk[2]}`) ON DELETE CASCADE";
			}
			$out .= 'CREATE TABLE IF NOT EXISTS `' . $prefix . "$name` (\n"
				. implode( ",\n", $lines ) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";
		}
		return $out;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function tables(): array {
		return [
			'datasets' => [
				'columns' => [
					'dataset_id' => 'VARCHAR(191) NOT NULL',
					'title' => 'VARCHAR(500) NOT NULL',
					'description' => 'TEXT NULL',
					'source_page' => 'VARCHAR(255) NULL',
					'source_item' => 'VARCHAR(32) NULL',
					'doi' => 'VARCHAR(255) NULL',
					'year' => 'SMALLINT NULL',
					'licence' => 'VARCHAR(32) NULL',
					'licence_label' => 'VARCHAR(255) NULL',
					'rights_basis' => 'VARCHAR(32) NULL',
					'rights_basis_label' => 'VARCHAR(255) NULL',
					'access_tier' => "VARCHAR(20) NOT NULL DEFAULT 'public'",
					'curator' => 'VARCHAR(255) NULL',
					'status' => "VARCHAR(20) NOT NULL DEFAULT 'draft'",
					'manifest_version' => 'VARCHAR(20) NOT NULL',
					'manifest_hash' => 'CHAR(64) NULL',
					'manifest_revision' => 'INT UNSIGNED NULL',
					'search_text' => 'TEXT NULL',
					'created_at' => 'DATETIME NOT NULL',
					'updated_at' => 'DATETIME NOT NULL',
				],
				'primary' => 'dataset_id',
				'indexes' => [ [ 'access_tier' ], [ 'status' ], [ 'year' ] ],
				'fulltext' => [ 'search_text' ],
			],
			'data_tables' => [
				'columns' => [
					'table_id' => 'VARCHAR(191) NOT NULL',
					'dataset_id' => 'VARCHAR(191) NOT NULL',
					'table_number' => 'VARCHAR(32) NOT NULL',
					'caption' => 'TEXT NOT NULL',
					'page' => 'VARCHAR(64) NOT NULL',
					'page_start' => 'INT NULL',
					'page_end' => 'INT NULL',
					'table_bbox' => 'VARCHAR(100) NULL',
					'caption_bbox' => 'VARCHAR(100) NULL',
					'source_index' => 'INT NULL',
					'pdf_file' => 'VARCHAR(255) NULL',
					'pdf_sha1' => 'CHAR(40) NULL',
					'pdf_revision' => 'INT UNSIGNED NULL',
					'source_file_access' => 'VARCHAR(20) NULL',
					'extractor' => 'VARCHAR(100) NULL',
					'extractor_version' => 'VARCHAR(50) NULL',
					'method' => "VARCHAR(20) NOT NULL DEFAULT 'manual'",
					'confidence' => 'DOUBLE NULL',
					'review_status' => "VARCHAR(20) NOT NULL DEFAULT 'unreviewed'",
					'reviewer' => 'VARCHAR(255) NULL',
					'review_date' => 'DATE NULL',
					'notes' => 'TEXT NULL',
					'search_text' => 'TEXT NULL',
					'created_at' => 'DATETIME NOT NULL',
					'updated_at' => 'DATETIME NOT NULL',
				],
				'primary' => 'table_id',
				'indexes' => [ [ 'dataset_id' ], [ 'review_status' ] ],
				'fulltext' => [ 'search_text' ],
				'foreign' => [ [ 'dataset_id', 'datasets', 'dataset_id' ] ],
			],
			'panels' => [
				'columns' => [
					'panel_id' => 'VARCHAR(191) NOT NULL',
					'table_id' => 'VARCHAR(191) NOT NULL',
					'panel_key' => 'VARCHAR(64) NOT NULL',
					'panel_label' => 'VARCHAR(255) NULL',
					'panel_order' => 'INT NOT NULL DEFAULT 0',
					'description' => 'TEXT NULL',
				],
				'primary' => 'panel_id',
				'unique' => [ [ 'table_id', 'panel_key' ] ],
				'foreign' => [ [ 'table_id', 'data_tables', 'table_id' ] ],
			],
			'data_columns' => [
				'columns' => [
					'column_id' => 'VARCHAR(191) NOT NULL',
					'table_id' => 'VARCHAR(191) NOT NULL',
					'panel_key' => 'VARCHAR(64) NOT NULL DEFAULT \'\'',
					'column_key' => 'VARCHAR(64) NOT NULL',
					'label' => 'VARCHAR(255) NOT NULL',
					'description' => 'TEXT NULL',
					'unit' => 'VARCHAR(64) NULL',
					'datatype' => 'VARCHAR(20) NOT NULL',
					'role' => 'VARCHAR(20) NOT NULL',
					'missing_token' => 'VARCHAR(32) NULL',
					'sort_order' => 'INT NOT NULL DEFAULT 0',
					'column_parent_key' => 'VARCHAR(64) NULL',
					'column_level' => 'INT NOT NULL DEFAULT 0',
					'search_text' => 'TEXT NULL',
				],
				'primary' => 'column_id',
				'unique' => [ [ 'table_id', 'panel_key', 'column_key' ] ],
				'indexes' => [ [ 'table_id', 'panel_key' ] ],
				'fulltext' => [ 'search_text' ],
				'foreign' => [ [ 'table_id', 'data_tables', 'table_id' ] ],
			],
			'observations' => [
				'columns' => [
					'obs_id' => 'VARCHAR(191) NOT NULL',
					'table_id' => 'VARCHAR(191) NOT NULL',
					'panel_key' => 'VARCHAR(64) NOT NULL DEFAULT \'\'',
					'row_index' => 'INT NOT NULL DEFAULT 0',
					'row_key' => 'VARCHAR(191) NOT NULL',
					'row_label' => 'VARCHAR(500) NOT NULL',
					'row_path' => 'VARCHAR(1000) NULL',
					'row_parent_key' => 'VARCHAR(191) NULL',
					'row_depth' => 'INT NOT NULL DEFAULT 0',
					'column_key' => 'VARCHAR(64) NOT NULL',
					'value_float' => 'DOUBLE NULL',
					'value_int' => 'BIGINT NULL',
					'value_text' => 'TEXT NULL',
					'value_date' => 'DATE NULL',
					'flag_significant' => 'TINYINT(1) NOT NULL DEFAULT 0',
					'flag_note' => 'VARCHAR(255) NULL',
					'footnote_keys' => 'VARCHAR(500) NULL',
					'source_page' => 'VARCHAR(64) NULL',
					'cell_bbox' => 'VARCHAR(100) NULL',
					'search_text' => 'TEXT NULL',
				],
				'primary' => 'obs_id',
				'indexes' => [
					[ 'table_id', 'panel_key', 'row_index', 'column_key' ],
					[ 'table_id', 'column_key' ],
					[ 'table_id', 'row_key' ],
				],
				'fulltext' => [ 'search_text' ],
				'foreign' => [ [ 'table_id', 'data_tables', 'table_id' ] ],
			],
			'footnotes' => [
				'columns' => [
					'footnote_id' => 'VARCHAR(191) NOT NULL',
					'table_id' => 'VARCHAR(191) NOT NULL',
					'footnote_key' => 'VARCHAR(64) NOT NULL',
					'marker_raw' => 'VARCHAR(32) NULL',
					'text' => 'TEXT NOT NULL',
					'scope' => 'VARCHAR(20) NOT NULL',
					'language' => 'VARCHAR(20) NULL',
					'sort_order' => 'INT NOT NULL DEFAULT 0',
					'status' => 'VARCHAR(20) NULL',
					'search_text' => 'TEXT NULL',
				],
				'primary' => 'footnote_id',
				'unique' => [ [ 'table_id', 'footnote_key' ] ],
				'fulltext' => [ 'search_text' ],
				'foreign' => [ [ 'table_id', 'data_tables', 'table_id' ] ],
			],
			'dataset_runs' => [
				'columns' => [
					'run_id' => 'VARCHAR(191) NOT NULL',
					'dataset_id' => 'VARCHAR(191) NOT NULL',
					'status' => "VARCHAR(20) NOT NULL DEFAULT 'queued'",
					'step' => 'VARCHAR(64) NULL',
					'message' => 'TEXT NULL',
					'page_title' => 'VARCHAR(255) NULL',
					'revision' => 'INT UNSIGNED NULL',
					'row_counts' => 'TEXT NULL',
					'report_json' => 'LONGTEXT NULL',
					'created_at' => 'DATETIME NOT NULL',
					'updated_at' => 'DATETIME NOT NULL',
				],
				'primary' => 'run_id',
				'indexes' => [ [ 'dataset_id' ], [ 'status' ] ],
			],
			'extraction_runs' => [
				'columns' => [
					'run_id' => 'VARCHAR(191) NOT NULL',
					'table_id' => 'VARCHAR(191) NOT NULL',
					'tool' => 'VARCHAR(100) NULL',
					'tool_version' => 'VARCHAR(50) NULL',
					'model' => 'VARCHAR(100) NULL',
					'prompt_version' => 'VARCHAR(50) NULL',
					'started_at' => 'DATETIME NULL',
					'finished_at' => 'DATETIME NULL',
					'status' => 'VARCHAR(20) NULL',
					'confidence' => 'DOUBLE NULL',
					'report_json' => 'LONGTEXT NULL',
					'diff_json' => 'LONGTEXT NULL',
				],
				'primary' => 'run_id',
				'indexes' => [ [ 'table_id' ] ],
				'foreign' => [ [ 'table_id', 'data_tables', 'table_id' ] ],
			],
		];
	}

}
