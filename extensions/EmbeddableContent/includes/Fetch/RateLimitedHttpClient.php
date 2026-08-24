<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * HttpClientInterface decorator that keeps the request rate polite to the
 * target's API and recovers from rate-limit responses:
 *
 *  - per-host-group minimum interval between requests (WMF hosts —
 *    wikidata.org / wikipedia.org / wikimedia.org — share ONE bucket,
 *    because the WMF rate-limits by user agent + IP across its API family);
 *  - on a 429 response, sleeps the `Retry-After` delay (or an exponential
 *    backoff when the server sent none) and retries, up to $maxRetries.
 *
 * The throttle is what keeps the instance inside Wikimedia's anonymous
 * request budget (the `fceb99d` 429 blocks the shared Oracle-Cloud IP when
 * several providers hit WMF endpoints back-to-back). All other hosts are
 * throttled per-host, which is harmless at this instance's scale.
 *
 * Unit-testable standalone: decorate a fake HttpClientInterface and assert
 * the sleep/retry behavior with a mocked time source.
 *
 * @license GPL-2.0-or-later
 */
class RateLimitedHttpClient implements HttpClientInterface {

	/** Host suffixes that share the WMF rate-limit bucket. */
	private const WIKIMEDIA_SUFFIXES = [ 'wikimedia.org', 'wikipedia.org', 'wikidata.org' ];

	/** Upper bound on a self-computed backoff, seconds. */
	private const MAX_BACKOFF = 30;

	private HttpClientInterface $inner;

	/** Minimum interval between two requests to the same bucket, seconds. */
	private float $minInterval;

	/** Retries after a 429 (beyond the first attempt). */
	private int $maxRetries;

	/** @var array<string,float> bucket => microtime(true) of the last attempt */
	private array $lastRequest = [];

	public function __construct(
		HttpClientInterface $inner,
		float $minInterval = 1.0,
		int $maxRetries = 2
	) {
		$this->inner = $inner;
		$this->minInterval = max( 0.0, $minInterval );
		$this->maxRetries = max( 0, $maxRetries );
	}

	public function getJson( string $url, array $query = [], float $timeout = 10.0, int $maxBytes = 1048576, array $headers = [] ): array {
		return $this->throttled(
			fn () => $this->inner->getJson( $url, $query, $timeout, $maxBytes, $headers ),
			$url
		);
	}

	public function postForm( string $url, array $form = [], array $headers = [], float $timeout = 10.0, int $maxBytes = 1048576 ): array {
		return $this->throttled(
			fn () => $this->inner->postForm( $url, $form, $headers, $timeout, $maxBytes ),
			$url
		);
	}

	/**
	 * Serializes the attempt: honors the bucket interval, then runs the call
	 * with 429 backoff + retry. A non-429 failure (or a 429 after all
	 * retries) propagates unchanged.
	 *
	 * @param callable():array $call
	 * @return array<string,mixed> decoded JSON
	 */
	private function throttled( callable $call, string $url ): array {
		$bucket = $this->bucketFor( $url );
		$this->waitForInterval( $bucket );
		$retries = 0;
		while ( true ) {
			$this->lastRequest[$bucket] = microtime( true );
			try {
				return $call();
			} catch ( ProviderException $e ) {
				if ( $e->getStatusCode() !== 429 || $retries >= $this->maxRetries ) {
					throw $e;
				}
				$delay = $e->getRetryAfter()
					?? (int)min( self::MAX_BACKOFF, 2 * ( 2 ** $retries ) );
				$retries++;
				usleep( (int)( $delay * 1_000_000 ) );
			}
		}
	}

	/**
	 * The bucket for a URL: one shared bucket for every WMF host (they
	 * rate-limit as a family), otherwise one bucket per host.
	 */
	private function bucketFor( string $url ): string {
		$host = strtolower( (string)parse_url( $url, PHP_URL_HOST ) );
		foreach ( self::WIKIMEDIA_SUFFIXES as $suffix ) {
			if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
				return 'wikimedia';
			}
		}
		return 'host:' . $host;
	}

	private function waitForInterval( string $bucket ): void {
		$last = $this->lastRequest[$bucket] ?? 0.0;
		$wait = $this->minInterval - ( microtime( true ) - $last );
		if ( $wait > 0 ) {
			usleep( (int)( $wait * 1_000_000 ) );
		}
	}
}
