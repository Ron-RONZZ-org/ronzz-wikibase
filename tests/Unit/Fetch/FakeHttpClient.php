<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\HttpClientInterface;
use EmbeddableContent\Fetch\ProviderException;

/**
 * Canned-response HTTP client for the fetch layer tests. Routes are matched
 * by URL substring in REGISTRATION ORDER — register the most specific needle
 * first (e.g. the full wbgetentities harvest before the nested label call).
 *
 * @license GPL-2.0-or-later
 */
final class FakeHttpClient implements HttpClientInterface {

	/** @var array<int,array{needle:string, respond:callable|array}> */
	private array $routes = [];

	/** @var array<int,array{method:string,url:string,params:array,headers:array}> */
	public array $calls = [];

	public function onJson( string $urlNeedle, array $json ): self {
		$this->routes[] = [ 'needle' => $urlNeedle, 'respond' => $json ];
		return $this;
	}

	public function onError( string $urlNeedle, string $message ): self {
		$this->routes[] = [
			'needle' => $urlNeedle,
			'respond' => static function () use ( $message ): array {
				throw new ProviderException( $message );
			},
		];
		return $this;
	}

	/**
	 * Route throwing an HTTP-status ProviderException (429 + Retry-After,
	 * 500, …) — the RateLimitedHttpClient backoff tests.
	 */
	public function onHttpError( string $urlNeedle, int $status, ?int $retryAfter = null ): self {
		$this->routes[] = [
			'needle' => $urlNeedle,
			'respond' => static function () use ( $status, $retryAfter ): array {
				throw new ProviderException( "HTTP {$status}", $status, $retryAfter );
			},
		];
		return $this;
	}

	/**
	 * Route failing with an HTTP status on the FIRST matching call, then
	 * returning the canned JSON — the "429 once, success after backoff"
	 * retry test.
	 */
	public function onHttpErrorOnce( string $urlNeedle, int $status, array $then, ?int $retryAfter = null ): self {
		$this->routes[] = [
			'needle' => $urlNeedle,
			'respond' => static function () use ( $status, $then, $retryAfter ): array {
				static $fired = false;
				if ( !$fired ) {
					$fired = true;
					throw new ProviderException( "HTTP {$status}", $status, $retryAfter );
				}
				return $then;
			},
		];
		return $this;
	}

	public function getJson( string $url, array $query = [], float $timeout = 10.0, int $maxBytes = 1048576, array $headers = [] ): array {
		// Mirror CurlHttpClient::withQuery so route needles can match the
		// query string (e.g. "action=wbsearchentities").
		if ( $query !== [] ) {
			$sep = strpos( $url, '?' ) === false ? '?' : '&';
			$url .= $sep . http_build_query( $query );
		}
		return $this->dispatch( 'getJson', $url, $query, $headers );
	}

	public function postForm( string $url, array $form = [], array $headers = [], float $timeout = 10.0, int $maxBytes = 1048576 ): array {
		return $this->dispatch( 'postForm', $url, $form, $headers );
	}

	private function dispatch( string $method, string $url, array $params, array $headers ): array {
		$this->calls[] = [ 'method' => $method, 'url' => $url, 'params' => $params, 'headers' => $headers ];
		foreach ( $this->routes as $route ) {
			if ( strpos( $url, $route['needle'] ) !== false ) {
				$respond = $route['respond'];
				if ( is_callable( $respond ) ) {
					return $respond();
				}
				return $respond;
			}
		}
		throw new ProviderException( "No fake route for: {$url}" );
	}
}
