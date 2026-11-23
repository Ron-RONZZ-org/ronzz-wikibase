<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Store;

use EmbeddableContent\Spec\LawSchema;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\RawSQLValue;

/**
 * The law-corpus store (`wb_law_*`). Laws, sections and articles are written
 * by the offline importer; readers are Special:Law and the law-* API modules.
 *
 * The citable item ids live on the rows (`item_id`) and are written by
 * LawCiteService on first citation. An import never clears them.
 *
 * @license GPL-2.0-or-later
 */
final class LawStore {

	public const PREFIX = LawSchema::TABLE_PREFIX;

	private IConnectionProvider $connections;

	public function __construct( IConnectionProvider $connections ) {
		$this->connections = $connections;
	}

	public function schemaExists(): bool {
		return $this->connections->getPrimaryDatabase()
			->tableExists( self::PREFIX . 'laws', __METHOD__ );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public function upsertLaw( array $row ): void {
		$row = $this->withTimestamps( $row, true );
		$this->connections->getPrimaryDatabase()->upsert(
			self::PREFIX . 'laws',
			$row,
			[ 'law_key' ],
			$this->valueSet( $row, [ 'law_key', 'item_id', 'created_at' ] ),
			__METHOD__
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public function upsertSections( array $rows ): void {
		if ( $rows === [] ) {
			return;
		}
		$rows = array_values( $rows );
		$this->connections->getPrimaryDatabase()->upsert(
			self::PREFIX . 'sections',
			$rows,
			[ 'section_id' ],
			$this->valueSet( $rows[0], [ 'section_id' ] ),
			__METHOD__
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public function upsertArticles( array $rows ): void {
		if ( $rows === [] ) {
			return;
		}
		$now = wfTimestamp( TS_MW );
		foreach ( $rows as &$row ) {
			$row['created_at'] = $row['created_at'] ?? $now;
			$row['updated_at'] = $now;
		}
		unset( $row );
		$rows = array_values( $rows );
		$this->connections->getPrimaryDatabase()->upsert(
			self::PREFIX . 'articles',
			$rows,
			[ [ 'law_key', 'reference_code', 'language' ] ],
			$this->valueSet(
				$rows[0],
				[ 'law_key', 'reference_code', 'language', 'item_id', 'created_at' ]
			),
			__METHOD__
		);
	}

	public function deleteLaw( string $lawKey ): void {
		$dbw = $this->connections->getPrimaryDatabase();
		$dbw->delete( self::PREFIX . 'articles', [ 'law_key' => $lawKey ], __METHOD__ );
		$dbw->delete( self::PREFIX . 'sections', [ 'law_key' => $lawKey ], __METHOD__ );
		$dbw->delete( self::PREFIX . 'laws', [ 'law_key' => $lawKey ], __METHOD__ );
	}

	public function deleteArticle( string $lawKey, string $articleId ): void {
		$this->connections->getPrimaryDatabase()->delete(
			self::PREFIX . 'articles',
			[ 'law_key' => $lawKey, 'article_id' => $articleId ],
			__METHOD__
		);
	}

	public function deleteArticleById( string $articleId ): void {
		$this->connections->getPrimaryDatabase()->delete(
			self::PREFIX . 'articles',
			[ 'article_id' => $articleId ],
			__METHOD__
		);
	}

	public function deleteSection( string $sectionId ): void {
		$this->connections->getPrimaryDatabase()->delete(
			self::PREFIX . 'sections',
			[ 'section_id' => $sectionId ],
			__METHOD__
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function getLaw( string $lawKey ): ?array {
		$row = $this->connections->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::PREFIX . 'laws' )
			->where( [ 'law_key' => $lawKey ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row === false ? null : (array)$row;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function getArticle( string $lawKey, string $reference, string $language = 'fr' ): ?array {
		$row = $this->fetchArticle( $lawKey, $reference, $language );
		if ( $row === false ) {
			$normalized = preg_replace( '/[\s_]+/u', '', $reference );
			if ( $normalized !== $reference && $normalized !== '' ) {
				$row = $this->fetchArticle( $lawKey, $normalized, $language );
			}
		}
		return $row === false ? null : (array)$row;
	}

	/**
	 * @return array<string,mixed>|false
	 */
	private function fetchArticle( string $lawKey, string $reference, string $language ) {
		return $this->connections->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'a.*, l.title AS law_title, l.nature AS law_nature, l.jurisdiction, l.source_url' )
			->from( self::PREFIX . 'articles', 'a' )
			->join( self::PREFIX . 'laws', 'l', 'l.law_key = a.law_key' )
			->where( [ 'a.law_key' => $lawKey, 'a.reference_code' => $reference, 'a.language' => $language ] )
			->caller( __METHOD__ )
			->fetchRow();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function listSections( string $lawKey ): array {
		return $this->rows( $this->connections->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::PREFIX . 'sections' )
			->where( [ 'law_key' => $lawKey ] )
			->orderBy( 'sort_order', 'ASC' )
			->orderBy( 'section_id', 'ASC' )
			->caller( __METHOD__ ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function listArticles( string $lawKey, int $limit = 500 ): array {
		$dbr = $this->connections->getReplicaDatabase();
		return $this->rows( $dbr->newSelectQueryBuilder()
			->select( [
				'law_key', 'reference_code', 'article_id', 'section_id',
				'section_path', 'status', 'version_start', 'version_end', 'item_id',
				$dbr->buildSubstring( 'text', 1, 300 ) . ' AS snippet',
			] )
			->from( self::PREFIX . 'articles' )
			->where( [ 'law_key' => $lawKey ] )
			->orderBy( 'section_path', 'ASC' )
			->orderBy( 'reference_code', 'ASC' )
			->limit( $limit )
			->caller( __METHOD__ ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function listLaws( ?string $jurisdiction = null, int $limit = 200, int $offset = 0 ): array {
		$qb = $this->connections->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'law_key, jurisdiction, title, short_title, nature, status, version_start, item_id' )
			->from( self::PREFIX . 'laws' )
			->orderBy( 'title', 'ASC' )
			->limit( $limit )
			->offset( $offset )
			->caller( __METHOD__ );
		if ( $jurisdiction !== null && $jurisdiction !== '' ) {
			$qb->where( [ 'jurisdiction' => $jurisdiction ] );
		}
		return $this->rows( $qb );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function searchLaws( string $query, ?string $jurisdiction = null, int $limit = 50 ): array {
		$dbr = $this->connections->getReplicaDatabase();
		$like = new LikeValue( $dbr->anyString(), $query, $dbr->anyString() );
		$qb = $dbr->newSelectQueryBuilder()
			->select( 'law_key, jurisdiction, title, short_title, nature, status, version_start, item_id' )
			->from( self::PREFIX . 'laws' )
			->where( $dbr->expr( 'title', IExpression::LIKE, $like ) )
			->orderBy( 'title', 'ASC' )
			->limit( $limit )
			->caller( __METHOD__ );
		if ( $jurisdiction !== null && $jurisdiction !== '' ) {
			$qb->where( [ 'jurisdiction' => $jurisdiction ] );
		}
		return $this->rows( $qb );
	}

	/**
	 * Article search. A reference-code prefix is the fast path; the section
	 * path and the text are the fallback.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchArticles( string $query, ?string $lawKey = null, int $limit = 50 ): array {
		$dbr = $this->connections->getReplicaDatabase();
		$like = new LikeValue( $dbr->anyString(), $query, $dbr->anyString() );
		$qb = $dbr->newSelectQueryBuilder()
			->select( [
				'a.law_key', 'a.reference_code', 'a.article_id', 'a.section_id',
				'a.section_path', 'a.status', 'a.item_id', 'l.title AS law_title',
				'l.jurisdiction', $dbr->buildSubstring( 'a.text', 1, 300 ) . ' AS snippet',
			] )
			->from( self::PREFIX . 'articles', 'a' )
			->join( self::PREFIX . 'laws', 'l', 'l.law_key = a.law_key' )
			->where( $dbr->expr( 'a.reference_code', IExpression::LIKE, $like ) )
			->orderBy( 'l.title', 'ASC' )
			->orderBy( 'a.reference_code', 'ASC' )
			->limit( $limit )
			->caller( __METHOD__ );
		if ( $lawKey !== null && $lawKey !== '' ) {
			$qb->where( [ 'a.law_key' => $lawKey ] );
		}
		return $this->rows( $qb );
	}

	public function setLawItemId( string $lawKey, string $itemId ): void {
		$this->connections->getPrimaryDatabase()->update(
			self::PREFIX . 'laws',
			[ 'item_id' => $itemId, 'updated_at' => wfTimestamp( TS_MW ) ],
			[ 'law_key' => $lawKey ],
			__METHOD__
		);
	}

	public function setArticleItemId( string $lawKey, string $reference, string $language, string $itemId ): void {
		$this->connections->getPrimaryDatabase()->update(
			self::PREFIX . 'articles',
			[ 'item_id' => $itemId, 'updated_at' => wfTimestamp( TS_MW ) ],
			[ 'law_key' => $lawKey, 'reference_code' => $reference, 'language' => $language ],
			__METHOD__
		);
	}

	/**
	 * @param array<string,mixed> $stats
	 */
	public function createRun( string $runId, string $source, string $mode ): void {
		$this->connections->getPrimaryDatabase()->insert(
			self::PREFIX . 'runs',
			[
				'run_id' => $runId,
				'source' => $source,
				'mode' => $mode,
				'started_at' => wfTimestamp( TS_MW ),
				'status' => 'running',
			],
			__METHOD__
		);
	}

	/**
	 * @param array<string,mixed> $stats
	 */
	public function finishRun( string $runId, string $status, array $stats, ?string $error = null ): void {
		$this->connections->getPrimaryDatabase()->update(
			self::PREFIX . 'runs',
			[
				'finished_at' => wfTimestamp( TS_MW ),
				'status' => $status,
				'stats_json' => json_encode( $stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'error' => $error,
			],
			[ 'run_id' => $runId ],
			__METHOD__
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( $queryBuilder ): array {
		$out = [];
		foreach ( $queryBuilder->fetchResultSet() as $row ) {
			$out[] = (array)$row;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function withTimestamps( array $row, bool $inserting ): array {
		$now = wfTimestamp( TS_MW );
		$row['updated_at'] = $now;
		if ( $inserting && !isset( $row['created_at'] ) ) {
			$row['created_at'] = $now;
		}
		return $row;
	}

	/**
	 * The mutable column => VALUES(column) set for a multi-row upsert,
	 * excluding the row key and the columns an import must never clobber
	 * (item_id, created_at). MySQL/MariaDB's VALUES() references the row
	 * being inserted, so one statement carries every row's own values.
	 *
	 * @param array<string,mixed> $row
	 * @param string[] $exclude
	 * @return array<string,\Wikimedia\Rdbms\RawSQLValue>
	 */
	private function valueSet( array $row, array $exclude ): array {
		$dbw = $this->connections->getPrimaryDatabase();
		$out = [];
		foreach ( array_keys( $row ) as $column ) {
			if ( in_array( $column, $exclude, true ) ) {
				continue;
			}
			$out[$column] = new RawSQLValue(
				'VALUES(' . $dbw->addIdentifierQuotes( $column ) . ')'
			);
		}
		return $out;
	}
}
