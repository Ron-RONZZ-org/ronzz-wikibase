<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Store;

use EmbeddableContent\Spec\DatasetSchema;

/**
 * Shape + semantic validation of a dataset manifest. The JSON Schema is the
 * external contract; this class is the wiki-side authoritative validator
 * (the API calls it, the job calls it again). Pure PHP: the PDF SHA1 check is
 * injected as a callable so the shape/referential rules are unit-testable.
 *
 * @license GPL-2.0-or-later
 */
final class DatasetManifestValidator {

	/** @var callable(string,?int):?string|null */
	private $pdfSha1;

	private const MAX_CELLS = 2000;

	public function __construct( ?callable $pdfSha1 = null ) {
		$this->pdfSha1 = $pdfSha1;
	}

	/**
	 * Fills the defaults the API/store rely on (a single implicit panel, the
	 * stable table id, default flags). Does not validate.
	 *
	 * @param array<string,mixed> $manifest
	 * @return array<string,mixed>
	 */
	public function normalize( array $manifest ): array {
		if ( !is_array( $manifest['dataset'] ?? null ) || !is_array( $manifest['tables'] ?? null ) ) {
			return $manifest;
		}
		$datasetId = (string)( $manifest['dataset']['dataset_id'] ?? '' );
		$manifest['dataset']['status'] = $manifest['dataset']['status'] ?? 'draft';
		foreach ( $manifest['tables'] ?? [] as $ti => $table ) {
			if ( !is_array( $table ) ) {
				continue;
			}
			if ( empty( $table['table_id'] ) && $datasetId !== '' ) {
				$table['table_id'] = $datasetId . '-t' . self::slug( (string)( $table['table_number'] ?? '0' ) );
			}
			if ( empty( $table['panels'] ) ) {
				$table['panels'] = [ [
					'panel_key' => 'p1', 'panel_label' => null, 'panel_order' => 1, 'description' => null,
				] ];
			}
			$defaultPanel = (string)$table['panels'][0]['panel_key'];
			foreach ( $table['columns'] ?? [] as $ci => $column ) {
				$column['panel_key'] = $column['panel_key'] ?? $defaultPanel;
				$column['sort_order'] = $column['sort_order'] ?? $ci;
				$column['column_level'] = $column['column_level'] ?? 1;
				$table['columns'][$ci] = $column;
			}
			foreach ( $table['observations'] ?? [] as $oi => $obs ) {
				$obs['panel_key'] = $obs['panel_key'] ?? $defaultPanel;
				$obs['flag_significant'] = $obs['flag_significant'] ?? false;
				$obs['footnote_keys'] = $obs['footnote_keys'] ?? [];
				$obs['row_depth'] = $obs['row_depth'] ?? 0;
				$table['observations'][$oi] = $obs;
			}
			foreach ( $table['footnotes'] ?? [] as $fi => $footnote ) {
				$footnote['sort_order'] = $footnote['sort_order'] ?? $fi;
				$footnote['status'] = $footnote['status'] ?? 'matched';
				$table['footnotes'][$fi] = $footnote;
			}
			foreach ( $table['extraction_runs'] ?? [] as $ri => $run ) {
				$run['status'] = $run['status'] ?? 'ok';
				$table['extraction_runs'][$ri] = $run;
			}
			$manifest['tables'][$ti] = $table;
		}
		return $manifest;
	}

	/**
	 * @param array<string,mixed> $manifest
	 * @return array{status:string,checks:array<int,array<string,string>>,summary:array<string,int>}
	 */
	public function validate( array $manifest, bool $apply = false ): array {
		$manifest = $this->normalize( $manifest );
		$checks = [];
		$add = static function ( string $code, string $severity, string $path, string $message ) use ( &$checks ): void {
			$checks[] = [ 'code' => $code, 'severity' => $severity, 'path' => $path, 'message' => $message ];
		};

		if ( ( $manifest['manifest_version'] ?? null ) !== DatasetSchema::VERSION ) {
			$add( 'manifest-invalid', 'error', 'manifest_version',
				'manifest_version must be "' . DatasetSchema::VERSION . '"' );
		}
		$this->assertKeys( $manifest, '', $add );
		if ( is_array( $manifest['dataset'] ?? null ) ) {
			$this->assertKeys( $manifest['dataset'], 'dataset.', $add );
		}
		$tables = is_array( $manifest['tables'] ?? null ) ? $manifest['tables'] : [];
		foreach ( $tables as $ti => $table ) {
			if ( !is_array( $table ) ) {
				$add( 'manifest-invalid', 'error', "tables[$ti]", 'table must be an object' );
				continue;
			}
			$this->assertKeys( $table, 'tables[].', $add );
			foreach ( $table['panels'] ?? [] as $panel ) {
				if ( is_array( $panel ) ) {
					$this->assertKeys( $panel, 'tables[].panels[].', $add );
				}
			}
			foreach ( $table['columns'] ?? [] as $column ) {
				if ( is_array( $column ) ) {
					$this->assertKeys( $column, 'tables[].columns[].', $add );
				}
			}
			foreach ( $table['observations'] ?? [] as $obs ) {
				if ( is_array( $obs ) ) {
					$this->assertKeys( $obs, 'tables[].observations[].', $add );
				}
			}
			foreach ( $table['footnotes'] ?? [] as $footnote ) {
				if ( is_array( $footnote ) ) {
					$this->assertKeys( $footnote, 'tables[].footnotes[].', $add );
				}
			}
			foreach ( $table['extraction_runs'] ?? [] as $run ) {
				if ( is_array( $run ) ) {
					$this->assertKeys( $run, 'tables[].extraction_runs[].', $add );
				}
			}
		}

		foreach ( DatasetSchema::manifestFields() as $path => $spec ) {
			if ( !empty( $spec['virtual'] ) ) {
				continue;
			}
			$values = $this->valuesAt( $manifest, $path );
			$required = !empty( $spec['required'] ) || ( $apply && !empty( $spec['apply'] ) );
			if ( $values === [] ) {
				if ( $required ) {
					$add( 'manifest-invalid', 'error', $path, 'required field is missing' );
				}
				continue;
			}
			foreach ( $values as $node ) {
				$this->checkValue( $spec, $node['value'], $node['path'], $add );
			}
		}

		$this->checkSemantics( $manifest, $apply, $add );
		$summary = $this->summary( $manifest );
		$status = 'ok';
		foreach ( $checks as $check ) {
			if ( $check['severity'] === 'error' ) {
				$status = 'errors';
				break;
			}
			$status = 'warnings';
		}
		return [ 'status' => $status, 'checks' => $checks, 'summary' => $summary ];
	}

	/**
	 * @param array<string,mixed> $spec
	 * @param mixed $value
	 */
	private function checkValue( array $spec, $value, string $path, callable $add ): void {
		if ( $value === null ) {
			if ( empty( $spec['nullable'] ) ) {
				$add( 'manifest-invalid', 'error', $path, 'must not be null' );
			}
			return;
		}
		$type = $spec['type'];
		$ok = match ( $type ) {
			'string' => is_string( $value ),
			'integer' => is_int( $value ),
			'number' => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'array' => is_array( $value ),
			'object' => is_array( $value ),
			default => true,
		};
		if ( !$ok ) {
			$add( 'manifest-invalid', 'error', $path, "must be of type $type" );
			return;
		}
		if ( isset( $spec['enum'] ) && !in_array( $value, $spec['enum'], true ) ) {
			$add( 'manifest-invalid', 'error', $path,
				'must be one of: ' . implode( ', ', $spec['enum'] ) );
		}
		if ( isset( $spec['pattern'] ) && is_string( $value )
			&& !preg_match( '/' . $spec['pattern'] . '/', $value ) ) {
			$add( 'manifest-invalid', 'error', $path, 'does not match ' . $spec['pattern'] );
		}
		if ( isset( $spec['minimum'] ) && is_numeric( $value ) && $value < $spec['minimum'] ) {
			$add( 'manifest-invalid', 'error', $path, 'must be >= ' . $spec['minimum'] );
		}
		if ( isset( $spec['maximum'] ) && is_numeric( $value ) && $value > $spec['maximum'] ) {
			$add( 'manifest-invalid', 'error', $path, 'must be <= ' . $spec['maximum'] );
		}
		if ( $type === 'array' && is_array( $value ) ) {
			if ( isset( $spec['minItems'] ) && count( $value ) < $spec['minItems'] ) {
				$add( 'manifest-invalid', 'error', $path, 'has too few items' );
			}
			if ( isset( $spec['maxItems'] ) && count( $value ) > $spec['maxItems'] ) {
				$add( 'manifest-invalid', 'error', $path, 'has too many items' );
			}
			if ( isset( $spec['items'] ) ) {
				foreach ( $value as $i => $item ) {
					$this->checkValue( $spec['items'], $item, $path . '[' . $i . ']', $add );
				}
			}
		}
	}

	/**
	 * @param array<string,mixed> $manifest
	 */
	private function checkSemantics( array $manifest, bool $apply, callable $add ): void {
		$datasetId = is_array( $manifest['dataset'] ?? null )
			? (string)( $manifest['dataset']['dataset_id'] ?? '' ) : '';
		$tableIds = [];
		$cells = 0;
		$tables = is_array( $manifest['tables'] ?? null ) ? $manifest['tables'] : [];
		foreach ( $tables as $ti => $table ) {
			if ( !is_array( $table ) ) {
				continue;
			}
			$tablePath = "tables[$ti]";
			$tableId = (string)( $table['table_id'] ?? '' );
			$expected = $datasetId . '-t' . self::slug( (string)( $table['table_number'] ?? '0' ) );
			if ( $tableId !== '' && $tableId !== $expected ) {
				$add( 'manifest-invalid', 'error', "$tablePath.table_id",
					"table_id must be $expected" );
			}
			if ( isset( $tableIds[$tableId] ) ) {
				$add( 'manifest-invalid', 'error', "$tablePath.table_id", 'duplicate table_id' );
			}
			$tableIds[$tableId] = true;

			$panelKeys = [];
			foreach ( $table['panels'] ?? [] as $panel ) {
				$key = (string)( $panel['panel_key'] ?? '' );
				if ( isset( $panelKeys[$key] ) ) {
					$add( 'manifest-invalid', 'error', "$tablePath.panels", "duplicate panel_key $key" );
				}
				$panelKeys[$key] = true;
			}
			$defaultPanel = array_key_first( $panelKeys ) ?? 'p1';

			$columns = [];
			foreach ( $table['columns'] ?? [] as $ci => $column ) {
				$panel = (string)( $column['panel_key'] ?? $defaultPanel );
				$key = (string)( $column['column_key'] ?? '' );
				$id = $panel . "\0" . $key;
				if ( isset( $columns[$id] ) ) {
					$add( 'manifest-invalid', 'error', "$tablePath.columns[$ci]",
						"duplicate column_key $key in panel $panel" );
				}
				$columns[$id] = $column;
				if ( !isset( $panelKeys[$panel] ) ) {
					$add( 'referential-integrity', 'error', "$tablePath.columns[$ci].panel_key",
						"unknown panel_key $panel" );
				}
			}

			$footnotes = [];
			foreach ( $table['footnotes'] ?? [] as $fi => $footnote ) {
				$key = (string)( $footnote['footnote_key'] ?? '' );
				if ( isset( $footnotes[$key] ) ) {
					$add( 'manifest-invalid', 'error', "$tablePath.footnotes[$fi]",
						"duplicate footnote_key $key" );
				}
				$footnotes[$key] = $footnote;
			}
			$referenced = [];
			foreach ( $table['observations'] ?? [] as $oi => $obs ) {
				$cells++;
				$panel = (string)( $obs['panel_key'] ?? $defaultPanel );
				$columnKey = (string)( $obs['column_key'] ?? '' );
				$column = $columns[$panel . "\0" . $columnKey] ?? null;
				if ( $column === null ) {
					$add( 'referential-integrity', 'error', "$tablePath.observations[$oi].column_key",
						"unknown column_key $columnKey in panel $panel" );
				}
				foreach ( (array)( $obs['footnote_keys'] ?? [] ) as $key ) {
					$referenced[(string)$key] = true;
					if ( !isset( $footnotes[(string)$key] ) ) {
						$add( 'referential-integrity', 'error',
							"$tablePath.observations[$oi].footnote_keys", "unknown footnote_key $key" );
					}
				}
				$this->checkValueRule( $obs, $column, "$tablePath.observations[$oi]", $add );
			}
			foreach ( $footnotes as $key => $footnote ) {
				$scope = (string)( $footnote['scope'] ?? '' );
				if ( $scope !== 'table' && !isset( $referenced[$key] ) ) {
					$add( 'referential-integrity', 'error', "$tablePath.footnotes",
						"footnote $key is not referenced" );
				}
			}
			if ( $apply && $this->pdfSha1 !== null
				&& ( $table['pdf_file'] ?? '' ) !== '' && ( $table['pdf_sha1'] ?? '' ) !== '' ) {
				$actual = ( $this->pdfSha1 )( (string)$table['pdf_file'],
					isset( $table['pdf_revision'] ) ? (int)$table['pdf_revision'] : null );
				if ( $actual === null ) {
					$add( 'pdf-provenance', 'error', "$tablePath.pdf_file",
						'the source PDF does not exist' );
				} elseif ( strtolower( $actual ) !== strtolower( (string)$table['pdf_sha1'] ) ) {
					$add( 'pdf-provenance', 'error', "$tablePath.pdf_sha1",
						'the source PDF SHA1 does not match' );
				}
			}
		}
		if ( $cells > self::MAX_CELLS ) {
			$add( 'size-limit', 'error', 'tables',
				"the manifest has $cells cells; the v1 cap is " . self::MAX_CELLS );
		}
	}

	private function checkValueRule( array $obs, ?array $column, string $path, callable $add ): void {
		if ( !array_key_exists( 'value_text', $obs ) || !is_string( $obs['value_text'] ) ) {
			$add( 'manifest-invalid', 'error', "$path.value_text",
				'value_text must carry the verbatim cell string' );
		}
		$datatype = (string)( $column['datatype'] ?? '' );
		$typed = [
			'float' => 'value_float', 'integer' => 'value_int', 'date' => 'value_date',
		];
		if ( isset( $typed[$datatype] ) ) {
			if ( !isset( $obs[$typed[$datatype]] ) ) {
				$add( 'manifest-invalid', 'error', "$path." . $typed[$datatype],
					"a $datatype column needs $typed[$datatype]" );
			}
		}
	}

	/**
	 * @param array<string,mixed> $manifest
	 * @return array<string,int>
	 */
	private function summary( array $manifest ): array {
		$summary = [ 'tables' => 0, 'panels' => 0, 'columns' => 0, 'observations' => 0, 'footnotes' => 0 ];
		$tables = is_array( $manifest['tables'] ?? null ) ? $manifest['tables'] : [];
		foreach ( $tables as $table ) {
			if ( !is_array( $table ) ) {
				continue;
			}
			$summary['tables']++;
			$summary['panels'] += count( $table['panels'] ?? [] );
			$summary['columns'] += count( $table['columns'] ?? [] );
			$summary['observations'] += count( $table['observations'] ?? [] );
			$summary['footnotes'] += count( $table['footnotes'] ?? [] );
		}
		return $summary;
	}

	/**
	 * @param array<string,mixed> $doc
	 * @return array<int,array{path:string,value:mixed}>
	 */
	private function valuesAt( array $doc, string $path ): array {
		$nodes = [ [ 'path' => '', 'value' => $doc ] ];
		foreach ( explode( '.', $path ) as $part ) {
			$isArray = str_ends_with( $part, '[]' );
			$key = $isArray ? substr( $part, 0, -2 ) : $part;
			$next = [];
			foreach ( $nodes as $node ) {
				if ( !is_array( $node['value'] ) || !array_key_exists( $key, $node['value'] ) ) {
					continue;
				}
				$child = $node['value'][$key];
				$base = $node['path'] === '' ? $key : $node['path'] . '.' . $key;
				if ( $isArray ) {
					if ( !is_array( $child ) ) {
						continue;
					}
					foreach ( $child as $i => $item ) {
						$next[] = [ 'path' => $base . '[' . $i . ']', 'value' => $item ];
					}
				} else {
					$next[] = [ 'path' => $base, 'value' => $child ];
				}
			}
			$nodes = $next;
		}
		return $nodes;
	}

	/**
	 * @param array<string,mixed> $object
	 */
	private function assertKeys( array $object, string $prefix, callable $add ): void {
		$allowed = [];
		foreach ( DatasetSchema::manifestFields() as $path => $_ ) {
			if ( !str_starts_with( $path, $prefix ) ) {
				continue;
			}
			$rest = substr( $path, strlen( $prefix ) );
			$segment = preg_split( '/[.\[]/', $rest )[0] ?? '';
			if ( $segment !== '' ) {
				$allowed[$segment] = true;
			}
		}
		foreach ( array_keys( $object ) as $key ) {
			if ( !isset( $allowed[$key] ) ) {
				$add( 'manifest-invalid', 'error', ( $prefix === '' ? '' : $prefix ) . $key,
					'unknown field' );
			}
		}
	}

	private static function slug( string $value ): string {
		return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' );
	}
}
