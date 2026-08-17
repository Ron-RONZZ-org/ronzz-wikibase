<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Open Library provider (books, ISBN/OLID — the deterministic source for
 * books). REST only. No DOI or Wikidata-Q lookup, so those return null.
 *
 * @license GPL-2.0-or-later
 */
class OpenLibraryProvider implements WorkProvider {

	private const SEARCH_API = 'https://openlibrary.org/search.json';
	private const ISBN_API = 'https://openlibrary.org/isbn';

	private HttpClientInterface $http;
	private float $timeout;

	public function __construct( HttpClientInterface $http, float $timeout = 10.0 ) {
		$this->http = $http;
		$this->timeout = $timeout;
	}

	public function searchByTitle( string $title ): array {
		$data = $this->http->getJson( self::SEARCH_API, [
			'title' => $title,
			'limit' => 10,
		], $this->timeout );
		$out = [];
		foreach ( $data['docs'] ?? [] as $doc ) {
			if ( !is_array( $doc ) || empty( $doc['title'] ) ) {
				continue;
			}
			$out[] = new WorkRecord(
				title: (string)$doc['title'],
				publisher: isset( $doc['publisher'][0] ) ? (string)$doc['publisher'][0] : null,
				isbn: isset( $doc['isbn_13'][0] ) ? (string)$doc['isbn_13'][0] : null,
				issuedYear: isset( $doc['first_publish_year'] ) ? (int)$doc['first_publish_year'] : null,
				provider: 'openlibrary',
				providerId: isset( $doc['key'] ) ? (string)$doc['key'] : null
			);
		}
		return $out;
	}

	public function byDoi( string $doi ): ?WorkRecord {
		return null; // books rarely carry DOIs — Crossref is the DOI source
	}

	public function byIsbn( string $isbn ): ?WorkRecord {
		$data = $this->http->getJson( self::ISBN_API . '/' . $isbn . '.json', [], $this->timeout );
		if ( empty( $data['title'] ) ) {
			return null;
		}
		return new WorkRecord(
			title: (string)$data['title'],
			publisher: isset( $data['publishers'][0] ) ? (string)$data['publishers'][0] : null,
			isbn: isset( $data['isbn_13'][0] ) ? (string)$data['isbn_13'][0] : null,
			issuedYear: $this->yearFromPublishDate( $data['publish_date'] ?? null ),
			provider: 'openlibrary',
			providerId: isset( $data['key'] ) ? (string)$data['key'] : null
		);
	}

	private function yearFromPublishDate( $value ): ?int {
		if ( !is_string( $value ) || preg_match( '/(19|20)\d{2}/', $value, $m ) !== 1 ) {
			return null;
		}
		return (int)$m[0];
	}
}
