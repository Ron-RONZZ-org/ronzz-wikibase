<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * OpenStreetMap Nominatim search (osm-places feature).
 *
 * Server-side, SSRF-allowlisted (the wiring pins the single
 * nominatim.openstreetmap.org host) and rate-limited through the shared
 * RateLimitedHttpClient (Nominatim's usage policy: max 1 req/s). Used at
 * harvest-on-pick to auto-match a harvested place LABEL to an OSM entity —
 * the top result prefills the person place field with its
 * node|way|relation/<id> form behind the fetch-match-confirm banner (the
 * portrait-license pattern). The search-as-you-type combobox itself stays
 * browser-first (osmsuggest.js) — the server only ever does ONE lookup per
 * harvested label.
 *
 * @license GPL-2.0-or-later
 */
class NominatimProvider {

	private const SEARCH_API = 'https://nominatim.openstreetmap.org/search';

	private HttpClientInterface $http;

	private float $timeout;

	public function __construct( HttpClientInterface $http, float $timeout = 6.0 ) {
		$this->http = $http;
		$this->timeout = $timeout;
	}

	/**
	 * Top OSM match for a place label, or null when the search yields
	 * nothing usable. Best-effort: the caller decides what to do when the
	 * endpoint is unreachable (it throws ProviderException — the harvest
	 * hook catches and degrades to the plain search hint).
	 *
	 * @return array{osmType:string,osmId:int,displayName:string}|null
	 */
	public function topMatchForLabel( string $label, string $acceptLanguage = 'en' ): ?array {
		$label = trim( $label );
		if ( $label === '' ) {
			return null;
		}
		$data = $this->http->getJson( self::SEARCH_API, [
			'q' => $label,
			'format' => 'jsonv2',
			'limit' => 5,
			'addressdetails' => 0,
			'accept-language' => $acceptLanguage,
		], $this->timeout );

		foreach ( $data as $row ) {
			$osmType = (string)( $row['osm_type'] ?? '' );
			$osmId = (int)( $row['osm_id'] ?? 0 );
			$displayName = (string)( $row['display_name'] ?? '' );
			if ( in_array( $osmType, [ 'node', 'way', 'relation' ], true ) && $osmId > 0 && $displayName !== '' ) {
				return [
					'osmType' => $osmType,
					'osmId' => $osmId,
					'displayName' => $displayName,
				];
			}
		}
		return null;
	}
}
