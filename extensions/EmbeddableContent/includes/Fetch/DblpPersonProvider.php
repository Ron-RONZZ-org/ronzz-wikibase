<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * dblp person provider (computer-science authors — tertiary in the cascade).
 *
 * Verified Aug 16 2026 against the live services:
 *  - author name search: dblp REST search API (reliable)
 *  - Wikidata-Q enrichment: bound-value SPARQL on sparql.dblp.org
 *    (free-text CONTAINS scans fail server-side; the QLever text predicate
 *    returns empty — bound VALUES lookups work)
 *  - dblp URLs ARE the KG entity URIs (https://dblp.org/pid/…)
 *
 * dblp does not index ORCID via a verified public predicate, so byOrcid()
 * returns null (Phase 1).
 *
 * @license GPL-2.0-or-later
 */
class DblpPersonProvider implements PersonProvider {

	private const SEARCH_API = 'https://dblp.org/search/author/api';
	private const SPARQL = 'https://sparql.dblp.org/sparql';

	private HttpClientInterface $http;
	private float $timeout;

	public function __construct( HttpClientInterface $http, float $timeout = 10.0 ) {
		$this->http = $http;
		$this->timeout = $timeout;
	}

	public function searchByName( string $name ): array {
		$data = $this->http->getJson( self::SEARCH_API, [
			'q' => $name,
			'format' => 'json',
			'h' => 10,
		], $this->timeout );

		$hits = $data['result']['hits']['hit'] ?? [];
		$records = [];
		foreach ( array_slice( $hits, 0, 5 ) as $hit ) {
			$info = $hit['info'] ?? [];
			$label = (string)( $info['author'] ?? '' );
			$url = (string)( $info['url'] ?? '' );
			if ( $label === '' || $url === '' ) {
				continue;
			}
			$records[] = new PersonRecord(
				label: $label,
				wikidataId: $this->wikidataForAuthor( $url ),
				provider: 'dblp',
				providerId: $url
			);
		}
		return $records;
	}

	public function byOrcid( string $orcid ): ?PersonRecord {
		// dblp has no verified public ORCID lookup predicate — Phase 1.
		return null;
	}

	/**
	 * Bound-value SPARQL enrichment: dblp:wikidata for one author URI.
	 * Degrades to a name-only record when the SPARQL endpoint is
	 * unavailable — the name is still a valid candidate.
	 */
	private function wikidataForAuthor( string $url ): ?string {
		$query = "PREFIX dblp: <https://dblp.org/rdf/schema#>\n"
			. "SELECT ?wikidata WHERE {\n"
			. "  VALUES ?author { <{$url}> }\n"
			. "  ?author dblp:wikidata ?wikidata\n"
			. "} LIMIT 1";
		try {
			$data = $this->sparql( $query );
		} catch ( ProviderException $e ) {
			return null; // documented degradation: name-only candidate
		}
		$binding = $data['results']['bindings'][0]['wikidata']['value'] ?? null;
		if ( !is_string( $binding ) ) {
			return null;
		}
		$qid = basename( $binding );
		return preg_match( '/^Q[1-9]\d*$/i', $qid ) === 1 ? $qid : null;
	}

	/**
	 * @return array<string,mixed> decoded SPARQL JSON results
	 */
	private function sparql( string $query ): array {
		return $this->http->postForm(
			self::SPARQL,
			[ 'query' => $query ],
			[ 'Accept' => 'application/sparql-results+json' ],
			$this->timeout
		);
	}
}
