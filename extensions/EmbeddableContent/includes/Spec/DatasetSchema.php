<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * The canonical contract of a curated dataset manifest.
 *
 * The manifest half of the contract; DatasetSqlSchema builds the DDL. Together
 * they produce every downstream artifact:
 *   - contract/dataset-manifest.schema.json   (the manifest JSON Schema)
 *   - contract/dataset-envelope.schema.json   (manifest + validation report)
 *   - contract/validation-report.schema.json  (the shared report shape)
 *   - contract/dataset-store-fields.json      (manifest path -> SQL column)
 *   - sql/dataset-schema.sql                  (the MySQL DDL, via DatasetSqlSchema)
 *
 * The MediaWiki MCP server and pdf-figures-mcp pin the emitted files verbatim
 * and validate their own output against them, so the extractor, the MCP client
 * and the wiki API cannot drift. Pure PHP (no MediaWiki runtime) so the
 * contract can be emitted anywhere and tested in the unit job.
 *
 * @license GPL-2.0-or-later
 */
final class DatasetSchema {

	public const VERSION = '1.0';

	public const TABLE_PREFIX = 'wb_dataset_';

	public const ERROR_CODES = [
		'manifest-invalid',
		'referential-integrity',
		'pdf-provenance',
		'page-conflict',
		'page-too-large',
		'schema-mismatch',
		'store-error',
		'wikibase-write',
		'permission',
		'size-limit',
	];

	/**
	 * The flat manifest registry. Paths use `[]` for array items and `.` for
	 * object members. `required` = required for validate and apply; `apply` =
	 * required for apply only; `store` = the logical SQL table.column;
	 * `generatedIfMissing` = the wiki may derive an ID the caller omitted.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function fields(): array {
		return [
			// --- envelope / version -------------------------------------
			'manifest_version' => [
				'type' => 'string', 'const' => self::VERSION, 'required' => true,
				'store' => 'datasets.manifest_version',
				'description' => 'Manifest contract version; a consumer rejects an unknown major.',
			],
			'dataset' => [ 'type' => 'object', 'virtual' => true, 'required' => true ],
			'tables' => [ 'type' => 'array', 'virtual' => true, 'required' => true ],

			// --- dataset -------------------------------------------------
			'dataset.dataset_id' => [
				'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*$', 'required' => true,
				'store' => 'datasets.dataset_id',
				'description' => 'Stable slug, e.g. bond-titus-1983.',
			],
			'dataset.title' => [ 'type' => 'string', 'minLength' => 1, 'required' => true, 'store' => 'datasets.title' ],
			'dataset.description' => [ 'type' => 'string', 'nullable' => true, 'store' => 'datasets.description' ],
			'dataset.source_page' => [ 'type' => 'string', 'apply' => true, 'store' => 'datasets.source_page' ],
			'dataset.source_item' => [ 'type' => 'string', 'pattern' => '^Q[0-9]+$', 'apply' => true, 'store' => 'datasets.source_item' ],
			'dataset.doi' => [ 'type' => 'string', 'nullable' => true, 'store' => 'datasets.doi' ],
			'dataset.year' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 9999, 'nullable' => true, 'store' => 'datasets.year' ],
			'dataset.licence' => [ 'type' => 'string', 'nullable' => true, 'store' => 'datasets.licence' ],
			'dataset.licence_label' => [ 'type' => 'string', 'nullable' => true, 'store' => 'datasets.licence_label' ],
			'dataset.rights_basis' => [ 'type' => 'string', 'nullable' => true, 'store' => 'datasets.rights_basis' ],
			'dataset.rights_basis_label' => [ 'type' => 'string', 'nullable' => true, 'store' => 'datasets.rights_basis_label' ],
			'dataset.access_tier' => [
				'type' => 'string', 'enum' => [ 'public', 'registered', 'internal' ], 'required' => true,
				'store' => 'datasets.access_tier',
				'description' => 'Audience tier. Orthogonal to the source PDF internal-use marker; never inferred from it.',
			],
			'dataset.curator' => [ 'type' => 'string', 'nullable' => true, 'store' => 'datasets.curator' ],
			'dataset.status' => [
				'type' => 'string', 'enum' => [ 'draft', 'reviewed', 'published', 'archived' ], 'nullable' => true,
				'store' => 'datasets.status', 'description' => 'Proposed status; the API controls the lifecycle.',
			],

			// --- table ---------------------------------------------------
			'tables[].table_id' => [
				'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*$', 'nullable' => true,
				'store' => 'data_tables.table_id', 'generatedIfMissing' => true,
				'description' => 'Stable key: dataset_id + "-t" + slug(table_number); the wiki derives it when omitted.',
			],
			'tables[].table_number' => [ 'type' => 'string', 'minLength' => 1, 'required' => true, 'store' => 'data_tables.table_number' ],
			'tables[].caption' => [ 'type' => 'string', 'required' => true, 'store' => 'data_tables.caption' ],
			'tables[].page' => [ 'type' => 'string', 'required' => true, 'store' => 'data_tables.page' ],
			'tables[].page_start' => [ 'type' => 'integer', 'minimum' => 1, 'nullable' => true, 'store' => 'data_tables.page_start' ],
			'tables[].page_end' => [ 'type' => 'integer', 'minimum' => 1, 'nullable' => true, 'store' => 'data_tables.page_end' ],
			'tables[].table_bbox' => [ 'type' => 'array', 'items' => [ 'type' => 'number' ], 'minItems' => 4, 'maxItems' => 4, 'nullable' => true, 'store' => 'data_tables.table_bbox' ],
			'tables[].caption_bbox' => [ 'type' => 'array', 'items' => [ 'type' => 'number' ], 'minItems' => 4, 'maxItems' => 4, 'nullable' => true, 'store' => 'data_tables.caption_bbox' ],
			'tables[].source_index' => [ 'type' => 'integer', 'minimum' => 0, 'nullable' => true, 'store' => 'data_tables.source_index' ],
			'tables[].pdf_file' => [ 'type' => 'string', 'apply' => true, 'store' => 'data_tables.pdf_file' ],
			'tables[].pdf_sha1' => [ 'type' => 'string', 'pattern' => '^[0-9a-f]{40}$', 'apply' => true, 'store' => 'data_tables.pdf_sha1' ],
			'tables[].pdf_revision' => [ 'type' => 'integer', 'minimum' => 1, 'nullable' => true, 'store' => 'data_tables.pdf_revision' ],
			'tables[].source_file_access' => [ 'type' => 'string', 'enum' => [ 'public', 'internal' ], 'nullable' => true, 'store' => 'data_tables.source_file_access' ],
			'tables[].extractor' => [ 'type' => 'string', 'apply' => true, 'store' => 'data_tables.extractor' ],
			'tables[].extractor_version' => [ 'type' => 'string', 'apply' => true, 'store' => 'data_tables.extractor_version' ],
			'tables[].method' => [ 'type' => 'string', 'enum' => [ 'text-layer', 'ocr', 'vision', 'manual' ], 'apply' => true, 'store' => 'data_tables.method' ],
			'tables[].confidence' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1, 'nullable' => true, 'store' => 'data_tables.confidence' ],
			'tables[].review_status' => [ 'type' => 'string', 'enum' => [ 'unreviewed', 'checked', 'verified' ], 'nullable' => true, 'store' => 'data_tables.review_status' ],
			'tables[].reviewer' => [ 'type' => 'string', 'nullable' => true, 'store' => 'data_tables.reviewer' ],
			'tables[].review_date' => [ 'type' => 'string', 'format' => 'date', 'nullable' => true, 'store' => 'data_tables.review_date' ],
			'tables[].notes' => [ 'type' => 'string', 'nullable' => true, 'store' => 'data_tables.notes' ],

			// --- panels --------------------------------------------------
			'tables[].panels' => [ 'type' => 'array', 'virtual' => true ],
			'tables[].panels[].panel_key' => [ 'type' => 'string', 'required' => true, 'store' => 'panels.panel_key' ],
			'tables[].panels[].panel_label' => [ 'type' => 'string', 'nullable' => true, 'store' => 'panels.panel_label' ],
			'tables[].panels[].panel_order' => [ 'type' => 'integer', 'minimum' => 0, 'store' => 'panels.panel_order' ],
			'tables[].panels[].description' => [ 'type' => 'string', 'nullable' => true, 'store' => 'panels.description' ],

			// --- columns -------------------------------------------------
			'tables[].columns' => [ 'type' => 'array', 'virtual' => true, 'required' => true ],
			'tables[].columns[].panel_key' => [ 'type' => 'string', 'nullable' => true, 'store' => 'data_columns.panel_key' ],
			'tables[].columns[].column_key' => [ 'type' => 'string', 'required' => true, 'store' => 'data_columns.column_key' ],
			'tables[].columns[].label' => [ 'type' => 'string', 'required' => true, 'store' => 'data_columns.label' ],
			'tables[].columns[].description' => [ 'type' => 'string', 'nullable' => true, 'store' => 'data_columns.description' ],
			'tables[].columns[].unit' => [ 'type' => 'string', 'nullable' => true, 'store' => 'data_columns.unit' ],
			'tables[].columns[].datatype' => [ 'type' => 'string', 'enum' => [ 'integer', 'float', 'text', 'date', 'boolean' ], 'required' => true, 'store' => 'data_columns.datatype' ],
			'tables[].columns[].role' => [ 'type' => 'string', 'enum' => [ 'dimension', 'measure', 'flag', 'note' ], 'required' => true, 'store' => 'data_columns.role' ],
			'tables[].columns[].missing_token' => [ 'type' => 'string', 'nullable' => true, 'store' => 'data_columns.missing_token' ],
			'tables[].columns[].sort_order' => [ 'type' => 'integer', 'minimum' => 0, 'store' => 'data_columns.sort_order' ],
			'tables[].columns[].column_parent_key' => [ 'type' => 'string', 'nullable' => true, 'store' => 'data_columns.column_parent_key' ],
			'tables[].columns[].column_level' => [ 'type' => 'integer', 'minimum' => 0, 'store' => 'data_columns.column_level' ],

			// --- observations --------------------------------------------
			'tables[].observations' => [ 'type' => 'array', 'virtual' => true, 'required' => true ],
			'tables[].observations[].panel_key' => [ 'type' => 'string', 'nullable' => true, 'store' => 'observations.panel_key' ],
			'tables[].observations[].row_index' => [ 'type' => 'integer', 'minimum' => 0, 'required' => true, 'store' => 'observations.row_index' ],
			'tables[].observations[].row_key' => [ 'type' => 'string', 'required' => true, 'store' => 'observations.row_key' ],
			'tables[].observations[].row_label' => [ 'type' => 'string', 'required' => true, 'store' => 'observations.row_label' ],
			'tables[].observations[].row_path' => [ 'type' => 'string', 'nullable' => true, 'store' => 'observations.row_path' ],
			'tables[].observations[].row_parent_key' => [ 'type' => 'string', 'nullable' => true, 'store' => 'observations.row_parent_key' ],
			'tables[].observations[].row_depth' => [ 'type' => 'integer', 'minimum' => 0, 'store' => 'observations.row_depth' ],
			'tables[].observations[].column_key' => [ 'type' => 'string', 'required' => true, 'store' => 'observations.column_key' ],
			'tables[].observations[].value_float' => [ 'type' => 'number', 'nullable' => true, 'store' => 'observations.value_float' ],
			'tables[].observations[].value_int' => [ 'type' => 'integer', 'nullable' => true, 'store' => 'observations.value_int' ],
			'tables[].observations[].value_text' => [ 'type' => 'string', 'nullable' => true, 'store' => 'observations.value_text' ],
			'tables[].observations[].value_date' => [ 'type' => 'string', 'format' => 'date', 'nullable' => true, 'store' => 'observations.value_date' ],
			'tables[].observations[].flag_significant' => [ 'type' => 'boolean', 'store' => 'observations.flag_significant' ],
			'tables[].observations[].flag_note' => [ 'type' => 'string', 'nullable' => true, 'store' => 'observations.flag_note' ],
			'tables[].observations[].footnote_keys' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'store' => 'observations.footnote_keys' ],
			'tables[].observations[].source_page' => [ 'type' => 'string', 'nullable' => true, 'store' => 'observations.source_page' ],
			'tables[].observations[].cell_bbox' => [ 'type' => 'array', 'items' => [ 'type' => 'number' ], 'minItems' => 4, 'maxItems' => 4, 'nullable' => true, 'store' => 'observations.cell_bbox' ],

			// --- footnotes -----------------------------------------------
			'tables[].footnotes' => [ 'type' => 'array', 'virtual' => true ],
			'tables[].footnotes[].footnote_key' => [ 'type' => 'string', 'required' => true, 'store' => 'footnotes.footnote_key' ],
			'tables[].footnotes[].marker_raw' => [ 'type' => 'string', 'nullable' => true, 'store' => 'footnotes.marker_raw' ],
			'tables[].footnotes[].text' => [ 'type' => 'string', 'required' => true, 'store' => 'footnotes.text' ],
			'tables[].footnotes[].scope' => [ 'type' => 'string', 'enum' => [ 'table', 'column', 'cell' ], 'required' => true, 'store' => 'footnotes.scope' ],
			'tables[].footnotes[].language' => [ 'type' => 'string', 'nullable' => true, 'store' => 'footnotes.language' ],
			'tables[].footnotes[].sort_order' => [ 'type' => 'integer', 'minimum' => 0, 'store' => 'footnotes.sort_order' ],
			'tables[].footnotes[].status' => [ 'type' => 'string', 'enum' => [ 'matched', 'missing_note', 'orphan_note' ], 'nullable' => true, 'store' => 'footnotes.status' ],

			// --- extraction runs -----------------------------------------
			'tables[].extraction_runs' => [ 'type' => 'array', 'virtual' => true ],
			'tables[].extraction_runs[].run_id' => [ 'type' => 'string', 'required' => true, 'store' => 'extraction_runs.run_id' ],
			'tables[].extraction_runs[].tool' => [ 'type' => 'string', 'apply' => true, 'store' => 'extraction_runs.tool' ],
			'tables[].extraction_runs[].tool_version' => [ 'type' => 'string', 'apply' => true, 'store' => 'extraction_runs.tool_version' ],
			'tables[].extraction_runs[].model' => [ 'type' => 'string', 'nullable' => true, 'store' => 'extraction_runs.model' ],
			'tables[].extraction_runs[].prompt_version' => [ 'type' => 'string', 'nullable' => true, 'store' => 'extraction_runs.prompt_version' ],
			'tables[].extraction_runs[].started_at' => [ 'type' => 'string', 'format' => 'date-time', 'nullable' => true, 'store' => 'extraction_runs.started_at' ],
			'tables[].extraction_runs[].finished_at' => [ 'type' => 'string', 'format' => 'date-time', 'nullable' => true, 'store' => 'extraction_runs.finished_at' ],
			'tables[].extraction_runs[].status' => [ 'type' => 'string', 'enum' => [ 'ok', 'needs_review', 'failed' ], 'nullable' => true, 'store' => 'extraction_runs.status' ],
			'tables[].extraction_runs[].confidence' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1, 'nullable' => true, 'store' => 'extraction_runs.confidence' ],
			'tables[].extraction_runs[].report_json' => [ 'type' => 'object', 'free' => true, 'nullable' => true, 'store' => 'extraction_runs.report_json' ],
			'tables[].extraction_runs[].diff_json' => [ 'type' => 'object', 'free' => true, 'nullable' => true, 'store' => 'extraction_runs.diff_json' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function manifestSchema(): array {
		return [
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
			'$id' => 'https://wikibase.ronzz.org/contract/dataset-manifest.schema.json',
			'title' => 'RonzzWikiBase curated dataset manifest',
			'description' => 'One dataset with N tables. The extractor, the MCP client and the wiki API all use this shape.',
		] + self::objectSchema( '', self::fields() );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function envelopeSchema(): array {
		return [
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
			'$id' => 'https://wikibase.ronzz.org/contract/dataset-envelope.schema.json',
			'title' => 'Dataset manifest transport envelope',
			'type' => 'object',
			'additionalProperties' => false,
			'properties' => [
				'manifest' => self::manifestSchemaBody(),
				'validation_report' => self::validationReportSchemaBody(),
				'manifest_path' => [ 'type' => 'string' ],
			],
			'required' => [ 'manifest' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function validationReportSchema(): array {
		return [
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
			'$id' => 'https://wikibase.ronzz.org/contract/validation-report.schema.json',
			'title' => 'Dataset manifest validation report',
		] + self::validationReportSchemaBody();
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function storeFields(): array {
		$fields = [];
		foreach ( self::fields() as $path => $spec ) {
			if ( !isset( $spec['store'] ) ) {
				continue;
			}
			$entry = [
				'store' => $spec['store'],
				'requiredFor' => array_values( array_filter( [
					!empty( $spec['required'] ) ? 'validate' : null,
					!empty( $spec['required'] ) || !empty( $spec['apply'] ) ? 'apply' : null,
				] ) ),
			];
			if ( !empty( $spec['generatedIfMissing'] ) ) {
				$entry['generatedIfMissing'] = true;
			}
			$fields[$path] = $entry;
		}
		return [
			'manifest_version' => self::VERSION,
			'table_prefix' => self::TABLE_PREFIX,
			'fields' => $fields,
			'generated' => self::generatedFields(),
		];
	}

	/**
	 * SQL fields the wiki fills, never the caller.
	 *
	 * @return array<string,string>
	 */
	private static function generatedFields(): array {
		return [
			'datasets.search_text' => 'dataset.title, dataset.description, dataset.source_page, dataset.doi',
			'datasets.manifest_hash' => 'sha256(manifest)',
			'datasets.manifest_revision' => 'saved manifest page revision',
			'datasets.created_at' => 'now',
			'datasets.updated_at' => 'now',
			'data_tables.dataset_id' => 'dataset.dataset_id',
			'data_tables.search_text' => 'tables[].caption, tables[].notes',
			'data_tables.created_at' => 'now',
			'data_tables.updated_at' => 'now',
			'panels.panel_id' => 'table_id + panel_key',
			'panels.table_id' => 'tables[].table_id',
			'data_columns.column_id' => 'table_id + panel_key + column_key',
			'data_columns.table_id' => 'tables[].table_id',
			'data_columns.search_text' => 'tables[].columns[].label, description, unit',
			'observations.obs_id' => 'table_id + row_index + panel_key + column_key',
			'observations.table_id' => 'tables[].table_id',
			'observations.search_text' => 'row_label, value_text',
			'footnotes.footnote_id' => 'table_id + footnote_key',
			'footnotes.table_id' => 'tables[].table_id',
			'footnotes.search_text' => 'tables[].footnotes[].text',
			'extraction_runs.table_id' => 'tables[].table_id',
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function validationReportExample(): array {
		return [
			'status' => 'ok',
			'checks' => [],
			'summary' => [ 'tables' => 1, 'panels' => 1, 'columns' => 2, 'observations' => 4, 'footnotes' => 1 ],
			'tool' => 'pdf-figures-mcp',
			'tool_version' => '0.2.0',
			'generated_at' => '2026-11-22T17:00:00Z',
		];
	}

	public static function manifestSchemaJson(): string {
		return self::json( self::manifestSchema() );
	}

	public static function envelopeSchemaJson(): string {
		return self::json( self::envelopeSchema() );
	}

	public static function validationReportSchemaJson(): string {
		return self::json( self::validationReportSchema() );
	}

	public static function storeFieldsJson(): string {
		return self::json( self::storeFields() );
	}

	/**
	 * @param mixed $data
	 */
	public static function json( $data ): string {
		return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}

	public static function sql(): string {
		return DatasetSqlSchema::sql();
	}

	/**
	 * @param array<string,array<string,mixed>> $fields
	 * @return array<string,mixed>
	 */
	private static function objectSchema( string $prefix, array $fields ): array {
		$properties = [];
		$required = [];
		$virtual = [];
		$children = [];
		foreach ( $fields as $path => $spec ) {
			if ( !str_starts_with( $path, $prefix ) ) {
				continue;
			}
			$rest = substr( $path, strlen( $prefix ) );
			if ( $rest === '' ) {
				continue;
			}
			$dot = strpos( $rest, '.' );
			$bracket = strpos( $rest, '[]' );
			if ( $bracket !== false && ( $dot === false || $bracket < $dot ) ) {
				$key = substr( $rest, 0, $bracket );
				$children[$key] = [ 'kind' => 'array', 'prefix' => $prefix . $key . '[].' ];
			} elseif ( $dot !== false ) {
				$key = substr( $rest, 0, $dot );
				$children[$key] = [ 'kind' => 'object', 'prefix' => $prefix . $key . '.' ];
			} else {
				if ( !empty( $spec['virtual'] ) ) {
					if ( !empty( $spec['required'] ) ) {
						$virtual[] = $rest;
					}
					continue;
				}
				$properties[$rest] = self::leafSchema( $spec );
				if ( !empty( $spec['required'] ) ) {
					$required[] = $rest;
				}
			}
		}
		foreach ( $children as $key => $child ) {
			$sub = self::objectSchema( $child['prefix'], $fields );
			$properties[$key] = $child['kind'] === 'array'
				? [ 'type' => 'array', 'items' => $sub ]
				: $sub;
		}
		foreach ( $virtual as $name ) {
			if ( isset( $properties[$name] ) ) {
				$required[] = $name;
			}
		}
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'properties' => $properties,
			'required' => array_values( array_unique( $required ) ),
		];
	}

	/**
	 * @param array<string,mixed> $spec
	 * @return array<string,mixed>|null
	 */
	private static function leafSchema( array $spec ): ?array {
		if ( !empty( $spec['virtual'] ) ) {
			return null;
		}
		$type = $spec['type'];
		if ( !empty( $spec['nullable'] ) ) {
			$type = [ $type, 'null' ];
		}
		$schema = [ 'type' => $type ];
		if ( isset( $spec['const'] ) ) {
			$schema['const'] = $spec['const'];
		}
		if ( isset( $spec['enum'] ) ) {
			$schema['enum'] = $spec['enum'];
			if ( !empty( $spec['nullable'] ) ) {
				$schema['enum'][] = null;
			}
		}
		foreach ( [ 'pattern', 'format', 'items', 'minItems', 'maxItems', 'minimum', 'maximum',
			'minLength', 'maxLength', 'description' ] as $key ) {
			if ( isset( $spec[$key] ) ) {
				$schema[$key] = $spec[$key];
			}
		}
		if ( !empty( $spec['free'] ) ) {
			$schema['additionalProperties'] = true;
		}
		return $schema;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function manifestSchemaBody(): array {
		return self::objectSchema( '', self::fields() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function validationReportSchemaBody(): array {
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'properties' => [
				'status' => [ 'type' => 'string', 'enum' => [ 'ok', 'warnings', 'errors' ] ],
				'checks' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'additionalProperties' => false,
						'properties' => [
							'code' => [ 'type' => 'string', 'enum' => self::ERROR_CODES ],
							'severity' => [ 'type' => 'string', 'enum' => [ 'error', 'warning', 'info' ] ],
							'path' => [ 'type' => 'string' ],
							'message' => [ 'type' => 'string' ],
							'hint' => [ 'type' => 'string' ],
						],
						'required' => [ 'code', 'severity', 'path', 'message' ],
					],
				],
				'summary' => [
					'type' => 'object',
					'additionalProperties' => false,
					'properties' => [
						'tables' => [ 'type' => 'integer', 'minimum' => 0 ],
						'panels' => [ 'type' => 'integer', 'minimum' => 0 ],
						'columns' => [ 'type' => 'integer', 'minimum' => 0 ],
						'observations' => [ 'type' => 'integer', 'minimum' => 0 ],
						'footnotes' => [ 'type' => 'integer', 'minimum' => 0 ],
					],
					'required' => [ 'tables', 'panels', 'columns', 'observations', 'footnotes' ],
				],
				'tool' => [ 'type' => 'string' ],
				'tool_version' => [ 'type' => 'string' ],
				'generated_at' => [ 'type' => 'string', 'format' => 'date-time' ],
			],
			'required' => [ 'status', 'checks', 'summary', 'tool', 'tool_version', 'generated_at' ],
		];
	}
}
