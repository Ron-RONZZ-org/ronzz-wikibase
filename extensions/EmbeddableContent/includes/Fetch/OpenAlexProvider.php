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

	public function searchByAuthorName( string $author, string $title = '' ): array {
		$params = [
			'filter' => 'author.search:' . $author,
			'per-page' => 10,
		];
		if ( $title !== '' ) {
			$params['search'] = $title;
		}
		$data = $this->http->getJson( self::API . '/works', $params, $this->timeout );
		return $this->mapWorks( $data['results'] ?? [] );
	}

	public function searchByAuthorEntities( array $qids, string $title = '' ): array {
		// OpenAlex author ids are A-ids, not Wikidata Q-ids — mapping requires
		// an extra lookup; the Wikidata hub covers semantic-entity lookups.
		return [];
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
				openalexId: $this->extractOpenAlexId( $author['id'] ?? null ),
				wikidataId: $this->extractQid( $author['ids']['wikidata'] ?? null ),
				provider: 'openalex',
				providerId: $author['id'] ?? null
			);
		}
		return $out;
	}

	/**
	 * OpenAlex ids are served as full URLs ("https://openalex.org/W2741809807")
	 * — the external-id property stores the bare id ("W2741809807", the
	 * formatter URL renders it back). Same for author A-ids.
	 */
	private function extractOpenAlexId( $value ): ?string {
		if ( !is_string( $value ) ) {
			return null;
		}
		$id = basename( $value );
		return preg_match( '/^[WA]\d+$/i', $id ) === 1 ? $id : null;
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
				openalexId: $this->extractOpenAlexId( $work['id'] ?? null ),
				pubmedId: $this->extractPmid( $work['ids']['pmid'] ?? null ),
				wikidataId: $this->extractQid( $work['ids']['wikidata'] ?? null ),
				issuedYear: $this->extractYear( $work['publication_date'] ?? null ),
				provider: 'openalex',
				providerId: isset( $work['id'] ) ? (string)$work['id'] : null,
				// Search responses carry the inverted-index abstract + the
				// keyword list — expose them when present (the page-content
				// fetch prefers the dedicated by-DOI endpoint, but a search
				// hit should not lose them).
				abstract: $this->abstractFromInvertedIndex( $work['abstract_inverted_index'] ?? null ),
				keywords: $this->keywordsFromWork( $work['keywords'] ?? null )
			);
		}
		return $out;
	}

	/**
	 * @return array{abstract: ?string, keywords: ?string, source: ?string}
	 */
	public function abstractAndKeywordsByDoi( string $doi ): array {
		return $this->abstractAndKeywordsFor( 'doi:' . rawurlencode( strtolower( $doi ) ) );
	}

	/**
	 * @return array{abstract: ?string, keywords: ?string, source: ?string}
	 */
	public function abstractAndKeywordsById( string $openalexId ): array {
		return $this->abstractAndKeywordsFor( $openalexId );
	}

	/**
	 * @return array{abstract: ?string, keywords: ?string, source: ?string}
	 */
	private function abstractAndKeywordsFor( string $selector ): array {
		try {
			$data = $this->http->getJson( self::API . '/works/' . $selector, [], $this->timeout );
		} catch ( \Throwable $e ) {
			return [ 'abstract' => null, 'keywords' => null, 'source' => null ];
		}
		if ( !is_array( $data ) || empty( $data['title'] ) ) {
			return [ 'abstract' => null, 'keywords' => null, 'source' => null ];
		}
		return [
			'abstract' => $this->abstractFromInvertedIndex( $data['abstract_inverted_index'] ?? null ),
			'keywords' => $this->keywordsFromWork( $data['keywords'] ?? null ),
			'source' => 'openalex',
		];
	}

	/**
	 * Reconstructs the plain-text abstract from OpenAlex's inverted index
	 * (word → positions). OpenAlex does not serve a plain-text abstract
	 * field; the index is the exact token stream (words AND punctuation), so
	 * joining the tokens at their positions reproduces the original text.
	 * Returns null when the index is absent; capped at 1500 chars.
	 *
	 * @param mixed $index
	 */
	private function abstractFromInvertedIndex( $index ): ?string {
		if ( !is_array( $index ) || $index === [] ) {
			return null;
		}
		$positions = [];
		foreach ( $index as $word => $poses ) {
			if ( !is_array( $poses ) ) {
				continue;
			}
			foreach ( $poses as $pos ) {
				if ( is_int( $pos ) || ( is_string( $pos ) && is_numeric( $pos ) ) ) {
					$positions[(int)$pos] = (string)$word;
				}
			}
		}
		if ( $positions === [] ) {
			return null;
		}
		ksort( $positions );
		$abstract = trim( implode( ' ', $positions ) );
		// The token join reintroduces stray spaces around punctuation.
		$abstract = (string)preg_replace( '/\s+([,.;:!?%\)\]])/', '$1', $abstract );
		$abstract = (string)preg_replace( '/([\(\[])\s+/', '$1', $abstract );
		$abstract = trim( $abstract );
		if ( mb_strlen( $abstract ) > 1500 ) {
			$abstract = mb_substr( $abstract, 0, 1499 ) . '…';
		}
		return $abstract;
	}

	/** Comma-joined OpenAlex keyword list, or null when absent. */
	private function keywordsFromWork( $keywords ): ?string {
		if ( !is_array( $keywords ) || $keywords === [] ) {
			return null;
		}
		$keywords = array_values( array_filter(
			$keywords,
			static fn ( $k ): bool => is_string( $k ) && $k !== ''
		) );
		return $keywords === [] ? null : implode( ', ', $keywords );
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
