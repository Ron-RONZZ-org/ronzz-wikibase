<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Error in the external-provider fetch layer: network failure, non-2xx HTTP
 * status, invalid JSON, response oversize, timeout, or allowlist (SSRF)
 * rejection. Never swallowed silently — ProviderClient surfaces per-provider
 * failures as warnings on ProviderResult.
 *
 * A non-2xx HTTP status carries the status code and — when the server sent a
 * `Retry-After` header — the suggested delay in seconds. The
 * RateLimitedHttpClient uses both to back off on 429 responses.
 *
 * @license GPL-2.0-or-later
 */
class ProviderException extends \RuntimeException {

	private ?int $statusCode;
	private ?int $retryAfter;

	/**
	 * @param string $message human-readable failure description
	 * @param int|null $statusCode HTTP status code, when the failure is a non-2xx response
	 * @param int|null $retryAfter seconds suggested by a `Retry-After` header, when present
	 */
	public function __construct(
		string $message,
		?int $statusCode = null,
		?int $retryAfter = null
	) {
		parent::__construct( $message );
		$this->statusCode = $statusCode;
		$this->retryAfter = $retryAfter;
	}

	public function getStatusCode(): ?int {
		return $this->statusCode;
	}

	public function getRetryAfter(): ?int {
		return $this->retryAfter;
	}
}
