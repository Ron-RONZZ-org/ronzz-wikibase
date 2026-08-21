<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * GitHub software provider for Special:AddSoftware (issue #26): repository
 * search + full record by full name. Secondary provider in the software
 * cascade (Wikidata first) — unauthenticated rate limit is 60 req/h.
 *
 * Requires `api.github.com` in the CurlHttpClient SSRF allowlist.
 *
 * @license GPL-2.0-or-later
 */
class GitHubSoftwareProvider implements SoftwareProvider {

	private const API = 'https://api.github.com';

	private HttpClientInterface $http;
	private float $timeout;

	public function __construct( HttpClientInterface $http, float $timeout = 10.0 ) {
		$this->http = $http;
		$this->timeout = $timeout;
	}

	public function searchByName( string $name ): array {
		$data = $this->http->getJson( self::API . '/search/repositories', [
			'q' => $name,
			'per_page' => 10,
			'sort' => 'stars',
			'order' => 'desc',
		], $this->timeout );
		$out = [];
		foreach ( $data['items'] ?? [] as $item ) {
			if ( !is_array( $item ) || !isset( $item['full_name'] ) ) {
				continue;
			}
			$out[] = $this->recordFromRepo( $item, 'github' );
		}
		return $out;
	}

	/**
	 * Full record by `owner/name` (the pick step — fresh API call so the
	 * review form shows the current homepage/license/description).
	 */
	public function byFullName( string $fullName ): ?SoftwareRecord {
		// GitHub REST repo paths keep the literal slash (URL-encoding it as
		// %2F returns an API error); validate the shape instead.
		if ( preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $fullName ) !== 1 ) {
			return null;
		}
		$data = $this->http->getJson( self::API . '/repos/' . $fullName, [], $this->timeout );
		if ( !is_array( $data ) || !isset( $data['full_name'] ) ) {
			return null;
		}
		return $this->recordFromRepo( $data, 'github' );
	}

	/**
	 * @param array<string,mixed> $repo GitHub repository object
	 */
	private function recordFromRepo( array $repo, string $provider ): SoftwareRecord {
		$license = null;
		if ( is_array( $repo['license'] ?? null ) && !empty( $repo['license']['spdx_id'] ) ) {
			$license = (string)$repo['license']['spdx_id'];
		}
		return new SoftwareRecord(
			label: (string)( $repo['full_name'] ?? $repo['name'] ?? '' ),
			description: isset( $repo['description'] ) ? (string)$repo['description'] : null,
			githubFullName: isset( $repo['full_name'] ) ? (string)$repo['full_name'] : null,
			website: isset( $repo['homepage'] ) ? (string)$repo['homepage'] : null,
			sourceRepository: isset( $repo['html_url'] ) ? (string)$repo['html_url'] : null,
			license: $license,
			programmingLanguage: isset( $repo['language'] ) ? (string)$repo['language'] : null,
			provider: $provider,
			providerId: isset( $repo['full_name'] ) ? (string)$repo['full_name'] : null
		);
	}
}
