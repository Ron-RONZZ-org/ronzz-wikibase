<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * cURL-backed HttpClientInterface with SSRF allowlist enforcement.
 *
 *  - Only hosts in $allowedHosts are connectable (exact match, https only)
 *  - Redirects are followed manually (max 3), every hop re-checked against
 *    the allowlist
 *  - Response body is capped at $maxBytes (aborts the transfer)
 *  - JSON responses are decoded strictly (JSON_THROW_ON_ERROR)
 *
 * @license GPL-2.0-or-later
 */
class CurlHttpClient implements HttpClientInterface {

	/** Default host allowlist for the ronzz-wikibase providers. */
	public const DEFAULT_ALLOWED_HOSTS = [
		'www.wikidata.org',
		'query.wikidata.org',
		'sparql.dblp.org',
		'dblp.org',
		'api.openalex.org',
		'api.crossref.org',
		'openlibrary.org',
		'pub.orcid.org',
		'viaf.org',
		'api.github.com', // issue #26: GitHubSoftwareProvider (Special:AddSoftware)
		'www.googleapis.com', // YouTube Data API v3 (Special:AddSource YouTube classes)
	];

	private const MAX_REDIRECTS = 3;

	/** @var string[] */
	private array $allowedHosts;

	private string $userAgent;

	public function __construct(
		array $allowedHosts = self::DEFAULT_ALLOWED_HOSTS,
		string $userAgent = 'ronzz-wikibase/0.1 (+https://github.com/Ron-RONZZ-org/ronzz-wikibase)'
	) {
		$this->allowedHosts = array_map( 'strtolower', $allowedHosts );
		$this->userAgent = $userAgent;
	}

	public function getJson( string $url, array $query = [], float $timeout = 10.0, int $maxBytes = 1048576, array $headers = [] ): array {
		return $this->execute( $this->withQuery( $url, $query ), [], $headers, $timeout, $maxBytes, false );
	}

	public function postForm( string $url, array $form = [], array $headers = [], float $timeout = 10.0, int $maxBytes = 1048576 ): array {
		return $this->execute( $url, $form, $headers, $timeout, $maxBytes, true );
	}

	/**
	 * @param array<string,mixed> $postFields
	 * @param array<string,string> $headers
	 * @return array<string,mixed> decoded JSON
	 */
	private function execute( string $url, array $postFields, array $headers, float $timeout, int $maxBytes, bool $isPost ): array {
		$url = $this->assertAllowlisted( $url );

		for ( $redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++ ) {
			$body = '';
			$status = 0;
			$location = null;

			$ch = curl_init();
			curl_setopt_array( $ch, [
				CURLOPT_URL => $url,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_HEADER => false,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => (int)min( 10, max( 1, (int)ceil( $timeout ) ) ),
				CURLOPT_TIMEOUT => (int)max( 1, (int)ceil( $timeout ) ),
				CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
				CURLOPT_USERAGENT => $this->userAgent,
				CURLOPT_HTTPHEADER => $this->buildHeaders( $headers, $isPost ),
				CURLOPT_HEADERFUNCTION => static function ( $ch, string $line ) use ( &$status, &$location ): int {
					if ( preg_match( '/^HTTP\/\S+\s+(\d{3})/', $line, $m ) === 1 ) {
						$status = (int)$m[1];
					}
					if ( stripos( $line, 'Location:' ) === 0 ) {
						$location = trim( substr( $line, 9 ) );
					}
					return strlen( $line );
				},
				CURLOPT_WRITEFUNCTION => static function ( $ch, string $chunk ) use ( &$body, $maxBytes ): int {
					if ( strlen( $body ) + strlen( $chunk ) > $maxBytes ) {
						// Return 0 to abort: curl reports CURLE_WRITE_ERROR.
						return 0;
					}
					$body .= $chunk;
					return strlen( $chunk );
				},
			] );

			if ( $isPost ) {
				curl_setopt( $ch, CURLOPT_POST, true );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $postFields ) );
			}

			$ok = curl_exec( $ch );
			$errno = curl_errno( $ch );
			$error = curl_error( $ch );
			// NOTE: no curl_close() — it is a no-op since PHP 8.0 and
			// deprecated on 8.5; the handle is released when $ch goes out
			// of scope.

			if ( $ok === false ) {
				if ( $errno === CURLE_WRITE_ERROR ) {
					throw new ProviderException( "Response from {$url} exceeds {$maxBytes} bytes" );
				}
				throw new ProviderException( "HTTP request to {$url} failed: {$error} (curl {$errno})" );
			}

			if ( $status >= 300 && $status < 400 && $location !== null ) {
				$url = $this->assertAllowlisted( $this->resolveUrl( $url, $location ) );
				continue;
			}

			if ( $status < 200 || $status >= 300 ) {
				throw new ProviderException( "HTTP {$status} from {$url}" );
			}

			try {
				$decoded = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
			} catch ( \JsonException $e ) {
				throw new ProviderException( "Invalid JSON from {$url}: {$e->getMessage()}" );
			}
			if ( !is_array( $decoded ) ) {
				throw new ProviderException( "Unexpected JSON payload from {$url}" );
			}
			return $decoded;
		}

		throw new ProviderException( "Too many redirects for {$url}" );
	}

	private function withQuery( string $url, array $query ): string {
		if ( $query === [] ) {
			return $url;
		}
		$sep = strpos( $url, '?' ) === false ? '?' : '&';
		return $url . $sep . http_build_query( $query );
	}

	/**
	 * @param array<string,string> $headers
	 * @return string[]
	 */
	private function buildHeaders( array $headers, bool $isPost ): array {
		$out = [];
		foreach ( $headers as $name => $value ) {
			$out[] = "{$name}: {$value}";
		}
		// SPARQL endpoints answer JSON only when asked.
		if ( !isset( $headers['Accept'] ) ) {
			$out[] = 'Accept: application/json';
		}
		return $out;
	}

	private function assertAllowlisted( string $url ): string {
		$parts = parse_url( $url );
		if ( $parts === false || !isset( $parts['scheme'], $parts['host'] ) ) {
			throw new ProviderException( "Invalid provider URL: {$url}" );
		}
		if ( strtolower( $parts['scheme'] ) !== 'https' ) {
			throw new ProviderException( "Only https is allowed: {$url}" );
		}
		$host = strtolower( $parts['host'] );
		if ( !in_array( $host, $this->allowedHosts, true ) ) {
			throw new ProviderException( "Host not in provider allowlist: {$host}" );
		}
		return $url;
	}

	private function resolveUrl( string $base, string $location ): string {
		if ( strpos( $location, '://' ) !== false ) {
			return $location;
		}
		$parts = parse_url( $base );
		$scheme = strtolower( $parts['scheme'] ?? 'https' );
		$host = strtolower( $parts['host'] ?? '' );
		if ( $location[0] === '/' ) {
			return "{$scheme}://{$host}{$location}";
		}
		$basePath = dirname( $parts['path'] ?? '/' );
		return "{$scheme}://{$host}" . rtrim( $basePath, '/' ) . '/' . $location;
	}
}
