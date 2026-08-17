<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * OpenAlex provider (scholarly works + authors, all disciplines) — REST only.
 * OpenAlex does not index ISBN and has no verified Wikidata-Q lookup filter,
 * so byIsbn()/byWikidataId() return null (Phase 1; Wikidata is the hub for
 * QID lookups).
 *
 * @license GPL-2.0-or-later
 */
class OpenAlexProvider implements PersonProvider, WorkProvider {

	private const API = 'https://api.openalex.org';

	private HttpClientInterface $http;
	private float $timeout;

	public function __construct( HttpClientInterface $http, float $timeout = 10.0 ) {
		$this->http = $http;
		$this->timeout = $timeout;
	}

	public function searchByName( string $name ): array {
		$data = $this->http->getJson( self::API . '/authors', [
			'search' => $name,
			'per-page' => 10,
		], $this->timeout );
		return $this->mapAuthors( $data['results'] ?? [] );
	}

	public function byOrcid( string $orcid ): ?PersonRecord {
		$data = $this->http->getJson( self::API . '/authors/orcid:' . $orcid, [], $this->timeout );
		$records = $this->mapAuthors( [ $data ] );
		return $records[0] ?? null;
	}

	public function searchByTitle( string $title ): array {
		$data = $this->http->getJson( self::API . '/works', [
			'search' => $title,
			'per-page' => 10,
		], $this->timeout );
		return $this->mapWorks( $data['results'] ?? [] );
	}

	public function byDoi( string $doi ): ?WorkRecord {
		$data = $this->http->getJson( self::API . '/works/doi:' . rawurlencode( $doi ), [], $this->timeout );
		$records = $this->mapWorks( [ $data ] );
		return $records[0] ?? null;
	}

	public function byIsbn( string $isbn ): ?WorkRecord {
		return null; // OpenAlex does not index ISBN — use Open Library / Wikidata
	}

	/**
	 * @param array<int,array<string,mixed>> $authors
	 * @return PersonRecord[]
	 */
	private function mapAuthors( array $authors ): array {
		$out = [];
		foreach ( $authors as $author ) {
			if ( !is_array( $author ) || empty( $author['display_name'] ) ) {
				continue;
			}
			$out[] = new PersonRecord(
				label: (string)$author['display_name'],
				orcid: $this->extractOrcid( $author['orcid'] ?? null ),
				wikidataId: $this->extractQid( $author['ids']['wikidata'] ?? null ),
				provider: 'openalex',
				providerId: $author['id'] ?? null
			);
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $works
	 * @return WorkRecord[]
	 */
	private function mapWorks( array $works ): array {
		$out = [];
		foreach ( $works as $work ) {
			if ( !is_array( $work ) || empty( $work['title'] ) ) {
				continue;
			}
			$biblio = $work['biblio'] ?? [];
			$source = $work['primary_location']['source']['display_name'] ?? null;
			$firstPage = $biblio['first_page'] ?? null;
			$lastPage = $biblio['last_page'] ?? null;
			$pages = ( $firstPage !== null && $lastPage !== null && $firstPage !== $lastPage )
				? "{$firstPage}-{$lastPage}"
				: ( $firstPage ?? $lastPage );

			$out[] = new WorkRecord(
				title: (string)$work['title'],
				containerTitle: is_string( $source ) ? $source : null,
				publisher: isset( $work['publisher'] ) && is_string( $work['publisher'] ) ? $work['publisher'] : null,
				volume: isset( $biblio['volume'] ) ? (string)$biblio['volume'] : null,
				issue: isset( $biblio['issue'] ) ? (string)$biblio['issue'] : null,
				pages: $pages,
				doi: $this->extractDoi( $work['doi'] ?? null ),
				openalexId: isset( $work['id'] ) ? (string)$work['id'] : null,
				pubmedId: $this->extractPmid( $work['ids']['pmid'] ?? null ),
				wikidataId: $this->extractQid( $work['ids']['wikidata'] ?? null ),
				issuedYear: $this->extractYear( $work['publication_date'] ?? null ),
				provider: 'openalex',
				providerId: isset( $work['id'] ) ? (string)$work['id'] : null
			);
		}
		return $out;
	}

	private function extractOrcid( $orcid ): ?string {
		if ( !is_string( $orcid ) ) {
			return null;
		}
		$matches = [];
		return preg_match( '/\b[0-9]{4}-?[0-9]{4}-?[0-9]{4}-?[0-9]{3}[0-9X]\b/i', $orcid, $matches ) === 1
			? $matches[0]
			: null;
	}

	private function extractQid( $value ): ?string {
		if ( !is_string( $value ) ) {
			return null;
		}
		$qid = basename( $value );
		return preg_match( '/^Q[1-9]\d*$/i', $qid ) === 1 ? $qid : null;
	}

	private function extractDoi( $doi ): ?string {
		if ( !is_string( $doi ) ) {
			return null;
		}
		return preg_replace( '/^https?:\/\/doi\.org\//i', '', $doi );
	}

	private function extractPmid( $value ): ?string {
		if ( !is_string( $value ) ) {
			return null;
		}
		$matches = [];
		return preg_match( '/(\d{5,9})/', $value, $matches ) === 1 ? $matches[1] : null;
	}

	private function extractYear( $date ): ?int {
		if ( !is_string( $date ) || preg_match( '/^(\d{4})/', $date, $m ) !== 1 ) {
			return null;
		}
		return (int)$m[1];
	}
}
