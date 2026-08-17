<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * ORCID provider (researchers — the deterministic source for persons with an
 * ORCID iD). Public API, REST only; rate-limited (be polite). ORCID has no
 * Wikidata-Q lookup, so byWikidataId() returns null.
 *
 * @license GPL-2.0-or-later
 */
class OrcidProvider implements PersonProvider {

	private const API = 'https://pub.orcid.org/v3.0';

	private HttpClientInterface $http;
	private float $timeout;

	public function __construct( HttpClientInterface $http, float $timeout = 10.0 ) {
		$this->http = $http;
		$this->timeout = $timeout;
	}

	public function searchByName( string $name ): array {
		$data = $this->http->getJson( self::API . '/expanded-search/', [
			'q' => 'given-and-family-names:' . $name,
			'rows' => 10,
		], $this->timeout, 1048576, [ 'Accept' => 'application/json' ] );
		$out = [];
		foreach ( $data['expanded-result'] ?? [] as $row ) {
			if ( !is_array( $row ) || empty( $row['orcid-id'] ) ) {
				continue;
			}
			$given = (string)( $row['given-names'] ?? '' );
			$family = (string)( $row['family-names'] ?? '' );
			$out[] = new PersonRecord(
				label: trim( $given . ' ' . $family ),
				givenName: $given !== '' ? $given : null,
				familyName: $family !== '' ? $family : null,
				orcid: (string)$row['orcid-id'],
				provider: 'orcid',
				providerId: (string)$row['orcid-id']
			);
		}
		return $out;
	}

	public function byOrcid( string $orcid ): ?PersonRecord {
		$data = $this->http->getJson(
			self::API . '/' . $orcid . '/record',
			[],
			$this->timeout,
			1048576,
			[ 'Accept' => 'application/json' ]
		);
		$name = $data['person']['name'] ?? null;
		if ( !is_array( $name ) ) {
			return null;
		}
		$given = (string)( $name['given-names']['value'] ?? '' );
		$family = (string)( $name['family-name']['value'] ?? '' );
		$path = (string)( $data['orcid-identifier']['path'] ?? $orcid );
		return new PersonRecord(
			label: trim( $given . ' ' . $family ),
			givenName: $given !== '' ? $given : null,
			familyName: $family !== '' ? $family : null,
			orcid: $path,
			provider: 'orcid',
			providerId: $path
		);
	}

}
