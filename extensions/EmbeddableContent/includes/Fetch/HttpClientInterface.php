<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Minimal HTTP transport for the fetch layer. Implementations MUST:
 *
 *  - return the decoded JSON payload as an associative array
 *  - throw ProviderException on network error, non-2xx status, invalid JSON,
 *    response oversize, or timeout
 *  - enforce the provider allowlist (SSRF defence) where applicable
 *
 * Kept free of MediaWiki/Wikibase dependencies so the whole fetch layer is
 * unit-testable standalone with a mocked implementation.
 *
 * @license GPL-2.0-or-later
 */
interface HttpClientInterface {

	/**
	 * GET with URL-encoded query parameters; response must be JSON.
	 *
	 * @param array<string,mixed> $query
	 * @param array<string,string> $headers extra headers (e.g. Accept)
	 * @return array<string,mixed> decoded JSON
	 */
	public function getJson( string $url, array $query = [], float $timeout = 10.0, int $maxBytes = 1048576, array $headers = [] ): array;

	/**
	 * POST with form-encoded fields (used for SPARQL queries); response must
	 * be JSON.
	 *
	 * @param array<string,mixed> $form
	 * @param array<string,string> $headers extra headers (e.g. Accept for SPARQL)
	 * @return array<string,mixed> decoded JSON
	 */
	public function postForm( string $url, array $form = [], array $headers = [], float $timeout = 10.0, int $maxBytes = 1048576 ): array;
}
