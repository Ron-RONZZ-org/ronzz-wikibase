<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Wikidata work provider (hub). Search via wbsearchentities; full harvest
 * (container, publisher, volume, issue, pages, DOI, ISBN, OpenAlex, PubMed,
 * issued year) via wbgetentities on pick; DOI/ISBN → QID via SPARQL.
 *
 * @license GPL-2.0-or-later
 */
class WikidataWorkProvider implements WorkProvider {

	private WikidataCore $core;

	public function __construct( HttpClientInterface $http, float $timeout = 10.0 ) {
		$this->core = new WikidataCore( $http, $timeout );
	}

	public function searchByTitle( string $title ): array {
		$out = [];
		foreach ( $this->core->searchRaw( $title ) as $row ) {
			$out[] = new WorkRecord(
				title: (string)( $row['label'] ?? $row['id'] ),
				description: isset( $row['description'] ) ? (string)$row['description'] : null,
				wikidataId: $row['id'],
				provider: 'wikidata',
				providerId: $row['id']
			);
		}
		return $out;
	}

	public function searchByAuthorName( string $author, string $title = '' ): array {
		return $this->mapAuthorRows( $this->core->searchWorksByAuthor( [], $author ) );
	}

	public function searchByAuthorEntities( array $qids, string $title = '' ): array {
		if ( $qids === [] ) {
			return [];
		}
		return $this->mapAuthorRows( $this->core->searchWorksByAuthor( $qids ) );
	}

	/**
	 * Maps the SPARQL author-search rows to light WorkRecords (the full
	 * harvest happens on pick, like searchByTitle()).
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return WorkRecord[]
	 */
	private function mapAuthorRows( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$qid = basename( (string)( $row['work']['value'] ?? '' ) );
			if ( preg_match( '/^Q[1-9]\d*$/i', $qid ) !== 1 ) {
				continue;
			}
			$label = (string)( $row['workLabel']['value'] ?? '' );
			$out[] = new WorkRecord(
				title: $label !== '' ? $label : $qid,
				description: isset( $row['workDescription']['value'] ) ? (string)$row['workDescription']['value'] : null,
				wikidataId: $qid,
				issuedYear: $this->core->yearFromTimeValue( (string)( $row['date']['value'] ?? '' ) ),
				classWikidataIds: $this->qidList( (string)( $row['class']['value'] ?? '' ) ),
				provider: 'wikidata',
				providerId: $qid
			);
		}
		return $out;
	}

	/** @return string[] a single class QID (or empty) */
	private function qidList( string $value ): array {
		if ( preg_match( '/(Q[1-9]\d*)$/', $value, $m ) === 1 ) {
			return [ $m[1] ];
		}
		return [];
	}

	public function byDoi( string $doi ): ?WorkRecord {
		$qid = $this->core->findItemByExternalId( 'P356', $doi );
		return $qid === null ? null : $this->byWikidataId( $qid );
	}

	public function byIsbn( string $isbn ): ?WorkRecord {
		$qid = $this->core->findItemByExternalId( 'P212', $isbn );
		return $qid === null ? null : $this->byWikidataId( $qid );
	}

	/**
	 * Full harvest from a Wikidata QID (the hub's pick step).
	 */
	public function byWikidataId( string $qid ): ?WorkRecord {
		$harvest = $this->core->harvestRaw( $qid );
		if ( $harvest === null ) {
			return null;
		}
		$itemLabels = $this->core->resolveItemLabels(
			$this->core->itemValueIds( $harvest['claims'], [ 'P1433', 'P123' ] )
		);
		return new WorkRecord(
			title: $harvest['label'],
			description: $harvest['description'],
			containerTitle: $this->core->itemLabel( $harvest['claims'], 'P1433', $itemLabels ),
			publisher: $this->core->itemLabel( $harvest['claims'], 'P123', $itemLabels ),
			volume: $this->core->stringValue( $harvest['claims'], 'P478' ),
			issue: $this->core->stringValue( $harvest['claims'], 'P433' ),
			pages: $this->core->stringValue( $harvest['claims'], 'P304' ),
			doi: $this->core->stringValue( $harvest['claims'], 'P356' ),
			isbn: $this->core->stringValue( $harvest['claims'], 'P212' ),
			openalexId: $this->core->stringValue( $harvest['claims'], 'P10283' ),
			pubmedId: $this->core->stringValue( $harvest['claims'], 'P698' ),
			wikidataId: $qid,
			issuedYear: $this->core->yearValue( $harvest['claims'], 'P577' ),
			classWikidataIds: $this->core->itemValueIds( $harvest['claims'], [ 'P31' ] ),
			provider: 'wikidata',
			providerId: $qid
		);
	}
}
