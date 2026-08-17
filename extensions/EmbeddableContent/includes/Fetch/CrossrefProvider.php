<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Crossref provider (DOI registry — the deterministic source for articles).
 * REST only. Crossref has no Wikidata-Q lookup, so byWikidataId() returns
 * null (Phase 1; Wikidata is the hub).
 *
 * @license GPL-2.0-or-later
 */
class CrossrefProvider implements WorkProvider {

	private const API = 'https://api.crossref.org/works';

	private HttpClientInterface $http;
	private float $timeout;

	public function __construct( HttpClientInterface $http, float $timeout = 10.0 ) {
		$this->http = $http;
		$this->timeout = $timeout;
	}

	public function searchByTitle( string $title ): array {
		$data = $this->http->getJson( self::API, [
			'query.title' => $title,
			'rows' => 10,
		], $this->timeout );
		return $this->mapMessages( $data['message']['items'] ?? [] );
	}

	public function byDoi( string $doi ): ?WorkRecord {
		$data = $this->http->getJson( self::API . '/' . rawurlencode( strtolower( $doi ) ), [], $this->timeout );
		$records = $this->mapMessages( [ $data['message'] ?? [] ] );
		return $records[0] ?? null;
	}

	public function byIsbn( string $isbn ): ?WorkRecord {
		$data = $this->http->getJson( self::API, [
			'filter' => 'isbn:' . $isbn,
			'rows' => 1,
		], $this->timeout );
		$records = $this->mapMessages( $data['message']['items'] ?? [] );
		return $records[0] ?? null;
	}

	/**
	 * @param array<int,array<string,mixed>> $messages
	 * @return WorkRecord[]
	 */
	private function mapMessages( array $messages ): array {
		$out = [];
		foreach ( $messages as $message ) {
			if ( !is_array( $message ) || empty( $message['title'][0] ) ) {
				continue;
			}
			$out[] = new WorkRecord(
				title: (string)$message['title'][0],
				containerTitle: isset( $message['container-title'][0] ) ? (string)$message['container-title'][0] : null,
				publisher: isset( $message['publisher'] ) ? (string)$message['publisher'] : null,
				volume: isset( $message['volume'] ) ? (string)$message['volume'] : null,
				issue: isset( $message['issue'] ) ? (string)$message['issue'] : null,
				pages: isset( $message['page'] ) ? (string)$message['page'] : null,
				doi: isset( $message['DOI'] ) ? (string)$message['DOI'] : null,
				isbn: isset( $message['ISBN'][0] ) ? (string)$message['ISBN'][0] : null,
				issuedYear: $this->issuedYear( $message['issued']['date-parts'] ?? [] ),
				provider: 'crossref',
				providerId: isset( $message['DOI'] ) ? (string)$message['DOI'] : null
			);
		}
		return $out;
	}

	/**
	 * @param array<int,mixed> $dateParts
	 */
	private function issuedYear( array $dateParts ): ?int {
		$year = $dateParts[0][0] ?? null;
		return is_numeric( $year ) ? (int)$year : null;
	}
}
