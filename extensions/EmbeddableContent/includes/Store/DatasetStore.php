<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Store;

use EmbeddableContent\Spec\DatasetSchema;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

/**
 * The curated-dataset measurement store (wb_dataset_* tables).
 *
 * One transaction per apply: delete the dataset's rows, batch-insert the new
 * manifest, commit. Readers see the old or the new snapshot, never a partial
 * table. The manifest page remains the source of truth; a failed apply can be
 * replayed.
 *
 * @license GPL-2.0-or-later
 */
final class DatasetStore {

	public const PREFIX = 'wb_dataset_';

	private IConnectionProvider $connections;

	/** @var string[] groups allowed to read internal-tier datasets */
	private array $internalGroups;

	public function __construct( IConnectionProvider $connections, array $internalGroups = [] ) {
		$this->connections = $connections;
		$this->internalGroups = array_values( array_filter( $internalGroups, 'is_string' ) );
	}

	public function schemaExists(): bool {
		return $this->connections->getPrimaryDatabase()
			->tableExists( self::PREFIX . 'datasets', __METHOD__ );
	}

	/**
	 * Applies one manifest inside a single transaction.
	 *
	 * @param array<string,mixed> $manifest
	 * @return array<string,int> row counts
	 */
	public function applyManifest(
		array $manifest,
		string $manifestHash,
		int $manifestRevision,
		string $runId
	): array {
		$datasetId = (string)$manifest['dataset']['dataset_id'];
		$dbw = $this->connections->getPrimaryDatabase();
		$dbw->startAtomic( __METHOD__ );
		try {
			$this->deleteDatasetRows( $dbw, $datasetId );
			$counts = $this->insertManifest( $dbw, $manifest, $manifestHash, $manifestRevision );
			$dbw->endAtomic( __METHOD__ );
			return $counts;
		} catch ( Throwable $e ) {
			$dbw->rollback( __METHOD__ );
			throw $e;
		}
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function getDataset( string $datasetId ): ?array {
		$row = $this->connections->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::PREFIX . 'datasets' )
			->where( [ 'dataset_id' => $datasetId ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row === false ? null : (array)$row;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function getTable( string $tableId ): ?array {
		$row = $this->connections->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::PREFIX . 'data_tables' )
			->where( [ 'table_id' => $tableId ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row === false ? null : (array)$row;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function getPanels( string $tableId ): array {
		return $this->rows( $this->connections->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::PREFIX . 'panels' )
			->where( [ 'table_id' => $tableId ] )
			->orderBy( 'panel_order', 'ASC' )
			->caller( __METHOD__ ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function getColumns( string $tableId, ?string $panelKey = null ): array {
		$qb = $this->connections->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::PREFIX . 'data_columns' )
			->where( [ 'table_id' => $tableId ] )
			->orderBy( 'sort_order', 'ASC' )
			->caller( __METHOD__ );
		if ( $panelKey !== null && $panelKey !== '' ) {
			$qb->where( [ 'panel_key' => $panelKey ] );
		}
		return $this->rows( $qb );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function getObservations( string $tableId, ?string $panelKey = null ): array {
		$qb = $this->connections->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::PREFIX . 'observations' )
			->where( [ 'table_id' => $tableId ] )
			->orderBy( 'row_index', 'ASC' )
			->orderBy( 'column_key', 'ASC' )
			->caller( __METHOD__ );
		if ( $panelKey !== null && $panelKey !== '' ) {
			$qb->where( [ 'panel_key' => $panelKey ] );
		}
		return $this->rows( $qb );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function getFootnotes( string $tableId ): array {
		return $this->rows( $this->connections->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::PREFIX . 'footnotes' )
			->where( [ 'table_id' => $tableId ] )
			->orderBy( 'sort_order', 'ASC' )
			->caller( __METHOD__ ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function getExtractionRuns( string $tableId ): array {
		return $this->rows( $this->connections->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::PREFIX . 'extraction_runs' )
			->where( [ 'table_id' => $tableId ] )
			->orderBy( 'started_at', 'ASC' )
			->caller( __METHOD__ ) );
	}

	/**
	 * @param array<string,mixed> $filters dataset_id, table_id, panel_key, column_key, search, order, limit, offset
	 * @return array<int,array<string,mixed>>
	 */
	public function queryObservations( array $filters ): array {
		$dbr = $this->connections->getReplicaDatabase();
		$qb = $dbr->newSelectQueryBuilder()
			->select( [ 'table_id', 'panel_key', 'row_index', 'row_key', 'row_label', 'row_path',
				'column_key', 'value_float', 'value_int', 'value_text', 'value_date',
				'flag_significant', 'flag_note', 'footnote_keys', 'source_page' ] )
			->from( self::PREFIX . 'observations' )
			->caller( __METHOD__ );
		$datasetId = (string)( $filters['dataset_id'] ?? '' );
		$tableId = (string)( $filters['table_id'] ?? '' );
		if ( $tableId !== '' ) {
			$qb->where( [ 'table_id' => $tableId ] );
		} elseif ( $datasetId !== '' ) {
			$tableIds = $dbr->newSelectQueryBuilder()
				->select( 'table_id' )
				->from( self::PREFIX . 'data_tables' )
				->where( [ 'dataset_id' => $datasetId ] )
				->caller( __METHOD__ )
				->fetchFieldValues();
			if ( $tableIds === [] ) {
				return [];
			}
			$qb->where( [ 'table_id' => $tableIds ] );
		}
		if ( !empty( $filters['panel_key'] ) ) {
			$qb->where( [ 'panel_key' => (string)$filters['panel_key'] ] );
		}
		if ( !empty( $filters['column_key'] ) ) {
			$qb->where( [ 'column_key' => (string)$filters['column_key'] ] );
		}
		if ( !empty( $filters['search'] ) ) {
			$qb->where( 'search_text LIKE ' . $dbr->addQuotes( '%' . $filters['search'] . '%' ) );
		}
		$order = (string)( $filters['order'] ?? 'row_index' );
		$allowed = [ 'row_index', 'row_key', 'column_key', 'value_float', 'value_int', 'value_text' ];
		if ( !in_array( $order, $allowed, true ) ) {
			$order = 'row_index';
		}
		$limit = max( 1, min( (int)( $filters['limit'] ?? 500 ), 5000 ) );
		$offset = max( 0, (int)( $filters['offset'] ?? 0 ) );
		return $this->rows( $qb->orderBy( $order, 'ASC' )->limit( $limit )->offset( $offset ) );
	}

	/**
	 * @param array<string,mixed> $filters status, access_tier, year, search, limit, offset
	 * @return array<int,array<string,mixed>>
	 */
	public function listDatasets( array $filters = [] ): array {
		$dbr = $this->connections->getReplicaDatabase();
		$qb = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( self::PREFIX . 'datasets' )
			->orderBy( 'updated_at', 'DESC' )
			->caller( __METHOD__ );
		foreach ( [ 'status', 'access_tier', 'year' ] as $key ) {
			if ( isset( $filters[$key] ) && $filters[$key] !== '' ) {
				$qb->where( [ $key => $filters[$key] ] );
			}
		}
		if ( !empty( $filters['search'] ) ) {
			$qb->where( 'search_text LIKE ' . $dbr->addQuotes( '%' . $filters['search'] . '%' ) );
		}
		$limit = max( 1, min( (int)( $filters['limit'] ?? 100 ), 500 ) );
		$offset = max( 0, (int)( $filters['offset'] ?? 0 ) );
		return $this->rows( $qb->limit( $limit )->offset( $offset ) );
	}

	/**
	 * @return array<string,int>
	 */
	public function countsForDataset( string $datasetId ): array {
		$dbr = $this->connections->getReplicaDatabase();
		$tableIds = $dbr->newSelectQueryBuilder()
			->select( 'table_id' )
			->from( self::PREFIX . 'data_tables' )
			->where( [ 'dataset_id' => $datasetId ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		$count = static function ( string $table, array $where ) use ( $dbr ): int {
			return (int)$dbr->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( self::PREFIX . $table )
				->where( $where )
				->caller( __METHOD__ )
				->fetchField();
		};
		return [
			'datasets' => $count( 'datasets', [ 'dataset_id' => $datasetId ] ),
			'tables' => $count( 'data_tables', [ 'dataset_id' => $datasetId ] ),
			'panels' => $tableIds === [] ? 0 : $count( 'panels', [ 'table_id' => $tableIds ] ),
			'columns' => $tableIds === [] ? 0 : $count( 'data_columns', [ 'table_id' => $tableIds ] ),
			'observations' => $tableIds === [] ? 0 : $count( 'observations', [ 'table_id' => $tableIds ] ),
			'footnotes' => $tableIds === [] ? 0 : $count( 'footnotes', [ 'table_id' => $tableIds ] ),
		];
	}

	/**
	 * @param array<string,mixed> $dataset a datasets row
	 */
	public function canRead( UserIdentity $user, array $dataset ): bool {
		$tier = (string)( $dataset['access_tier'] ?? 'public' );
		if ( $tier === 'public' ) {
			return true;
		}
		$registered = $user->getId() !== 0;
		if ( $tier === 'registered' ) {
			return $registered;
		}
		if ( $tier !== 'internal' || !$registered || $this->internalGroups === [] ) {
			return false;
		}
		$groups = MediaWikiServices::getInstance()->getUserGroupManager()->getUserGroups( $user );
		return array_intersect( $groups, $this->internalGroups ) !== [];
	}

	public function createRun( string $runId, string $datasetId ): void {
		$dbw = $this->connections->getPrimaryDatabase();
		$now = $dbw->timestamp();
		$dbw->newInsertQueryBuilder()
			->insertInto( self::PREFIX . 'dataset_runs' )
			->row( [ 'run_id' => $runId, 'dataset_id' => $datasetId, 'status' => 'queued',
				'created_at' => $now, 'updated_at' => $now ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	public function updateRun( string $runId, array $fields ): void {
		$fields['updated_at'] = $this->connections->getPrimaryDatabase()->timestamp();
		$this->connections->getPrimaryDatabase()->newUpdateQueryBuilder()
			->update( self::PREFIX . 'dataset_runs' )
			->set( $fields )
			->where( [ 'run_id' => $runId ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function getRun( string $runId ): ?array {
		$row = $this->connections->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::PREFIX . 'dataset_runs' )
			->where( [ 'run_id' => $runId ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row === false ? null : (array)$row;
	}

	private function deleteDatasetRows( IDatabase $dbw, string $datasetId ): void {
		$tableIds = $dbw->newSelectQueryBuilder()
			->select( 'table_id' )
			->from( self::PREFIX . 'data_tables' )
			->where( [ 'dataset_id' => $datasetId ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( $tableIds !== [] ) {
			foreach ( [ 'observations', 'footnotes', 'extraction_runs', 'data_columns', 'panels' ] as $name ) {
				$dbw->newDeleteQueryBuilder()
					->deleteFrom( self::PREFIX . $name )
					->where( [ 'table_id' => $tableIds ] )
					->caller( __METHOD__ )
					->execute();
			}
		}
		$dbw->newDeleteQueryBuilder()->deleteFrom( self::PREFIX . 'data_tables' )
			->where( [ 'dataset_id' => $datasetId ] )->caller( __METHOD__ )->execute();
		$dbw->newDeleteQueryBuilder()->deleteFrom( self::PREFIX . 'datasets' )
			->where( [ 'dataset_id' => $datasetId ] )->caller( __METHOD__ )->execute();
	}

	/**
	 * @param array<string,mixed> $manifest
	 * @return array<string,int>
	 */
	private function insertManifest(
		IDatabase $dbw,
		array $manifest,
		string $manifestHash,
		int $manifestRevision
	): array {
		$dataset = $manifest['dataset'];
		$now = $dbw->timestamp();
		$datasetId = (string)$dataset['dataset_id'];
		$dbw->newInsertQueryBuilder()
			->insertInto( self::PREFIX . 'datasets' )
			->row( [
				'dataset_id' => $datasetId,
				'title' => (string)$dataset['title'],
				'description' => $dataset['description'] ?? null,
				'source_page' => $dataset['source_page'] ?? null,
				'source_item' => $dataset['source_item'] ?? null,
				'doi' => $dataset['doi'] ?? null,
				'year' => $dataset['year'] ?? null,
				'licence' => $dataset['licence'] ?? null,
				'licence_label' => $dataset['licence_label'] ?? null,
				'rights_basis' => $dataset['rights_basis'] ?? null,
				'rights_basis_label' => $dataset['rights_basis_label'] ?? null,
				'access_tier' => (string)( $dataset['access_tier'] ?? 'public' ),
				'curator' => $dataset['curator'] ?? null,
				'status' => (string)( $dataset['status'] ?? 'draft' ),
				'manifest_version' => (string)$manifest['manifest_version'],
				'manifest_hash' => $manifestHash,
				'manifest_revision' => $manifestRevision,
				'search_text' => $this->join( [ $dataset['title'], $dataset['description'],
					$dataset['source_page'], $dataset['doi'] ] ),
				'created_at' => $now,
				'updated_at' => $now,
			] )
			->caller( __METHOD__ )
			->execute();
		$counts = [ 'datasets' => 1, 'tables' => 0, 'panels' => 0, 'columns' => 0,
			'observations' => 0, 'footnotes' => 0 ];
		foreach ( $manifest['tables'] as $table ) {
			$counts['tables']++;
			$tableId = (string)$table['table_id'];
			$dbw->newInsertQueryBuilder()
				->insertInto( self::PREFIX . 'data_tables' )
				->row( [
					'table_id' => $tableId,
					'dataset_id' => $datasetId,
					'table_number' => (string)$table['table_number'],
					'caption' => (string)$table['caption'],
					'page' => (string)$table['page'],
					'page_start' => $table['page_start'] ?? null,
					'page_end' => $table['page_end'] ?? null,
					'table_bbox' => $this->bbox( $table['table_bbox'] ?? null ),
					'caption_bbox' => $this->bbox( $table['caption_bbox'] ?? null ),
					'source_index' => $table['source_index'] ?? null,
					'pdf_file' => $table['pdf_file'] ?? null,
					'pdf_sha1' => $table['pdf_sha1'] ?? null,
					'pdf_revision' => $table['pdf_revision'] ?? null,
					'source_file_access' => $table['source_file_access'] ?? null,
					'extractor' => $table['extractor'] ?? null,
					'extractor_version' => $table['extractor_version'] ?? null,
					'method' => (string)( $table['method'] ?? 'manual' ),
					'confidence' => $table['confidence'] ?? null,
					'review_status' => (string)( $table['review_status'] ?? 'unreviewed' ),
					'reviewer' => $table['reviewer'] ?? null,
					'review_date' => $table['review_date'] ?? null,
					'notes' => $table['notes'] ?? null,
					'search_text' => $this->join( [ $table['caption'], $table['notes'] ?? null ] ),
					'created_at' => $now,
					'updated_at' => $now,
				] )
				->caller( __METHOD__ )
				->execute();

			$panels = $table['panels'] ?? [];
			$panelKeys = [];
			foreach ( $panels as $panel ) {
				$panelKey = (string)$panel['panel_key'];
				$panelKeys[] = $panelKey;
				$dbw->newInsertQueryBuilder()
					->insertInto( self::PREFIX . 'panels' )
					->row( [ 'panel_id' => $tableId . '-' . $panelKey, 'table_id' => $tableId,
						'panel_key' => $panelKey, 'panel_label' => $panel['panel_label'] ?? null,
						'panel_order' => (int)( $panel['panel_order'] ?? 0 ),
						'description' => $panel['description'] ?? null ] )
					->caller( __METHOD__ )
					->execute();
				$counts['panels']++;
			}
			$defaultPanel = $panelKeys[0] ?? 'p1';

			$columnRows = [];
			foreach ( $table['columns'] as $column ) {
				$panelKey = (string)( $column['panel_key'] ?? $defaultPanel );
				$columnRows[] = [ 'column_id' => $tableId . '-' . ( $panelKey !== '' ? $panelKey : 'p0' )
					. '-' . $column['column_key'], 'table_id' => $tableId, 'panel_key' => $panelKey,
					'column_key' => (string)$column['column_key'], 'label' => (string)$column['label'],
					'description' => $column['description'] ?? null, 'unit' => $column['unit'] ?? null,
					'datatype' => (string)$column['datatype'], 'role' => (string)$column['role'],
					'missing_token' => $column['missing_token'] ?? null,
					'sort_order' => (int)( $column['sort_order'] ?? 0 ),
					'column_parent_key' => $column['column_parent_key'] ?? null,
					'column_level' => (int)( $column['column_level'] ?? 0 ),
					'search_text' => $this->join( [ $column['label'], $column['description'] ?? null,
						$column['unit'] ?? null ] ) ];
				$counts['columns']++;
			}
			$this->insertChunks( $dbw, 'data_columns', $columnRows );

			$obsRows = [];
			foreach ( $table['observations'] as $obs ) {
				$panelKey = (string)( $obs['panel_key'] ?? $defaultPanel );
				$obsRows[] = [ 'obs_id' => $tableId . '-r' . $obs['row_index'] . '-'
					. ( $panelKey !== '' ? $panelKey : 'p0' ) . '-' . $obs['column_key'],
					'table_id' => $tableId, 'panel_key' => $panelKey,
					'row_index' => (int)$obs['row_index'], 'row_key' => (string)$obs['row_key'],
					'row_label' => (string)$obs['row_label'], 'row_path' => $obs['row_path'] ?? null,
					'row_parent_key' => $obs['row_parent_key'] ?? null,
					'row_depth' => (int)( $obs['row_depth'] ?? 0 ),
					'column_key' => (string)$obs['column_key'], 'value_float' => $obs['value_float'] ?? null,
					'value_int' => $obs['value_int'] ?? null, 'value_text' => $obs['value_text'] ?? null,
					'value_date' => $obs['value_date'] ?? null,
					'flag_significant' => !empty( $obs['flag_significant'] ) ? 1 : 0,
					'flag_note' => $obs['flag_note'] ?? null,
					'footnote_keys' => $this->listValue( $obs['footnote_keys'] ?? [] ),
					'source_page' => $obs['source_page'] ?? null,
					'cell_bbox' => $this->bbox( $obs['cell_bbox'] ?? null ),
					'search_text' => $this->join( [ $obs['row_label'], $obs['value_text'] ?? null ] ) ];
				$counts['observations']++;
			}
			$this->insertChunks( $dbw, 'observations', $obsRows );

			$footnoteRows = [];
			foreach ( $table['footnotes'] ?? [] as $footnote ) {
				$footnoteRows[] = [ 'footnote_id' => $tableId . '-' . $footnote['footnote_key'],
					'table_id' => $tableId, 'footnote_key' => (string)$footnote['footnote_key'],
					'marker_raw' => $footnote['marker_raw'] ?? null, 'text' => (string)$footnote['text'],
					'scope' => (string)$footnote['scope'], 'language' => $footnote['language'] ?? null,
					'sort_order' => (int)( $footnote['sort_order'] ?? 0 ),
					'status' => $footnote['status'] ?? null,
					'search_text' => (string)$footnote['text'] ];
				$counts['footnotes']++;
			}
			$this->insertChunks( $dbw, 'footnotes', $footnoteRows );

			$runRows = [];
			foreach ( $table['extraction_runs'] ?? [] as $run ) {
				$runRows[] = [ 'run_id' => (string)$run['run_id'], 'table_id' => $tableId,
					'tool' => $run['tool'] ?? null, 'tool_version' => $run['tool_version'] ?? null,
					'model' => $run['model'] ?? null, 'prompt_version' => $run['prompt_version'] ?? null,
					'started_at' => $run['started_at'] ?? null, 'finished_at' => $run['finished_at'] ?? null,
					'status' => $run['status'] ?? null, 'confidence' => $run['confidence'] ?? null,
					'report_json' => $this->json( $run['report_json'] ?? null ),
					'diff_json' => $this->json( $run['diff_json'] ?? null ) ];
			}
			$this->insertChunks( $dbw, 'extraction_runs', $runRows );
		}
		return $counts;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function insertChunks( IDatabase $dbw, string $table, array $rows ): void {
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$dbw->newInsertQueryBuilder()
				->insertInto( self::PREFIX . $table )
				->rows( $chunk )
				->caller( __METHOD__ )
				->execute();
		}
	}

	private function bbox( $value ): ?string {
		return is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : null;
	}

	private function listValue( $value ): ?string {
		return is_array( $value ) && $value !== [] ? implode( ',', array_map( 'strval', $value ) ) : null;
	}

	private function json( $value ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}
		return is_string( $value ) ? $value : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function join( array $values ): string {
		return implode( ' ', array_filter( $values, static fn ( $v ) => is_string( $v ) && $v !== '' ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( $qb ): array {
		$out = [];
		foreach ( $qb->fetchResultSet() as $row ) {
			$out[] = (array)$row;
		}
		return $out;
	}
}
