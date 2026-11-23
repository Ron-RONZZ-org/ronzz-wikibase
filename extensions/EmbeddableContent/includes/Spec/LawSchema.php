<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * The law-corpus contract: laws, sections, articles and import runs.
 *
 * The corpus is built offline from the official open-data sources (DILA LEGI
 * for France; EUR-Lex for the EU). It is the read model behind Special:Law and
 * the law-* MCP tools; the citable Wikibase items are created on first
 * citation and never carry a classic Source: page.
 *
 * Pure PHP so the DDL and the table contract are unit-testable without
 * MediaWiki. The installer and the importer both read this class.
 *
 * @license GPL-2.0-or-later
 */
final class LawSchema {

	public const VERSION = '1.0';

	public const TABLE_PREFIX = 'wb_law_';

	public const ERROR_CODES = [
		'law-not-found',
		'article-not-found',
		'schema-missing',
		'import-failed',
		'permission',
		'size-limit',
	];

	/**
	 * The table contract. `primary` is the row key; `unique` are additional
	 * upsert keys; `indexes` are non-unique; `fulltext` columns get a MySQL
	 * FULLTEXT index.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function tables(): array {
		return [
			'laws' => [
				'columns' => [
					'law_key' => 'VARCHAR(64) NOT NULL',
					'jurisdiction' => "VARCHAR(16) NOT NULL DEFAULT 'FR'",
					'title' => 'VARCHAR(1000) NOT NULL',
					'short_title' => 'VARCHAR(500) NULL',
					'nature' => 'VARCHAR(32) NULL',
					'nor' => 'VARCHAR(32) NULL',
					'eli' => 'VARCHAR(255) NULL',
					'source_url' => 'VARCHAR(1000) NULL',
					'source' => "VARCHAR(16) NOT NULL DEFAULT 'dila'",
					'language' => "VARCHAR(20) NOT NULL DEFAULT 'fr'",
					'version_start' => 'DATE NULL',
					'version_end' => 'DATE NULL',
					'retrieved_at' => 'DATETIME NOT NULL',
					'content_hash' => 'CHAR(64) NULL',
					'item_id' => 'VARCHAR(32) NULL',
					'status' => "VARCHAR(20) NOT NULL DEFAULT 'in_force'",
					'search_text' => 'TEXT NULL',
					'created_at' => 'DATETIME NOT NULL',
					'updated_at' => 'DATETIME NOT NULL',
				],
				'primary' => 'law_key',
				'indexes' => [ [ 'jurisdiction' ], [ 'nature' ], [ 'status' ], [ 'item_id' ] ],
				'fulltext' => [ 'search_text' ],
			],
			'sections' => [
				'columns' => [
					'section_id' => 'VARCHAR(64) NOT NULL',
					'law_key' => 'VARCHAR(64) NOT NULL',
					'parent_id' => 'VARCHAR(64) NULL',
					'level' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
					'title' => 'VARCHAR(1000) NOT NULL',
					'path' => 'VARCHAR(2000) NULL',
					'sort_order' => 'INT NOT NULL DEFAULT 0',
				],
				'primary' => 'section_id',
				'indexes' => [ [ 'law_key' ], [ 'parent_id' ] ],
				'foreign' => [ [ 'law_key', 'laws', 'law_key' ] ],
			],
			'articles' => [
				'columns' => [
					'law_key' => 'VARCHAR(64) NOT NULL',
					'reference_code' => 'VARCHAR(191) NOT NULL',
					'language' => "VARCHAR(20) NOT NULL DEFAULT 'fr'",
					'article_id' => 'VARCHAR(64) NOT NULL',
					'section_id' => 'VARCHAR(64) NULL',
					'section_path' => 'VARCHAR(2000) NULL',
					'text' => 'MEDIUMTEXT NOT NULL',
					'status' => "VARCHAR(20) NOT NULL DEFAULT 'VIGUEUR'",
					'version_start' => 'DATE NULL',
					'version_end' => 'DATE NULL',
					'retrieved_at' => 'DATETIME NOT NULL',
					'content_hash' => 'CHAR(64) NULL',
					'item_id' => 'VARCHAR(32) NULL',
					'search_text' => 'MEDIUMTEXT NULL',
					'created_at' => 'DATETIME NOT NULL',
					'updated_at' => 'DATETIME NOT NULL',
				],
				'primary' => [ 'law_key', 'reference_code', 'language' ],
				'unique' => [ [ 'article_id' ] ],
				'indexes' => [ [ 'law_key', 'section_id' ], [ 'item_id' ], [ 'status' ] ],
				// No FULLTEXT here: the article body is already in `text`, and
				// a full-text copy would double the (large) corpus on disk.
				// `search_text` holds a short searchable snippet only.
				'foreign' => [ [ 'law_key', 'laws', 'law_key' ] ],
			],
			'runs' => [
				'columns' => [
					'run_id' => 'VARCHAR(64) NOT NULL',
					'source' => 'VARCHAR(16) NOT NULL',
					'mode' => 'VARCHAR(16) NOT NULL',
					'started_at' => 'DATETIME NOT NULL',
					'finished_at' => 'DATETIME NULL',
					'status' => "VARCHAR(20) NOT NULL DEFAULT 'running'",
					'stats_json' => 'TEXT NULL',
					'error' => 'TEXT NULL',
				],
				'primary' => 'run_id',
				'indexes' => [ [ 'source', 'started_at' ] ],
			],
		];
	}

	/**
	 * The DDL for every corpus table.
	 */
	public static function sql( string $prefix = self::TABLE_PREFIX ): string {
		return LawSqlSchema::sql( $prefix );
	}

	/**
	 * The columns of a table, as a plain list (used by the importer/store).
	 *
	 * @return string[]
	 */
	public static function columns( string $table ): array {
		return array_keys( self::tables()[$table]['columns'] ?? [] );
	}
}
