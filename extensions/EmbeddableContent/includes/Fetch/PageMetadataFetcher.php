<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Fetches and parses metadata for an arbitrary contributor-entered URL
 * (Special:AddSource website/webpage URL-first flow).
 *
 * SSRF defence is layered: the literal SsrfGuard checks run first (pure,
 * deterministic), then the default transport — MediaWiki's
 * HttpRequestFactory with `rejectLocalUrls` — refuses connections that
 * resolve to private/loopback IPs at connect time (DNS-rebinding safe).
 *
 * The transport is injectable for unit tests; the default is MW-bound and
 * only used in the running wiki.
 *
 * @license GPL-2.0-or-later
 */
final class PageMetadataFetcher {

	/** Upper bound on the fetched HTML body (2 MB). */
	public const MAX_HTML_BYTES = 2097152;

	/** @var callable(string,float):string|null */
	private $transport;

	/**
	 * @param callable(string,float):string|null $transport returns the HTML
	 *   body for a URL + timeout in seconds; throws on failure
	 */
	public function __construct( ?callable $transport = null ) {
		$this->transport = $transport;
	}

	/**
	 * Fetches the page and extracts metadata. Never throws: any failure
	 * (unsafe URL, network error, timeout, non-HTML body) yields null so the
	 * contributor simply fills the fields by hand.
	 */
	public function fetch( string $url, float $timeout = 8.0 ): ?PageMetadata {
		$url = SsrfGuard::validate( $url );
		if ( $url === null ) {
			return null;
		}
		try {
			$transport = $this->transport ?? $this->defaultTransport();
			// IDN hosts are normalized to punycode for the request (DNS/cURL
			// resolve the ASCII form); the caller keeps the Unicode URL.
			$html = $transport( SsrfGuard::asciiUrl( $url ), $timeout );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( !is_string( $html ) || $html === '' ) {
			return null;
		}
		return HtmlMetadataParser::extract( $html );
	}

	/**
	 * Fetches the raw HTML body (the source-archive conversion input).
	 * Never throws: an unsafe URL, a network error, a timeout, a non-string
	 * body or a body over MAX_HTML_BYTES yields null.
	 */
	public function fetchHtml( string $url, float $timeout = 8.0 ): ?string {
		$url = SsrfGuard::validate( $url );
		if ( $url === null ) {
			return null;
		}
		try {
			$transport = $this->transport ?? $this->defaultTransport();
			$html = $transport( SsrfGuard::asciiUrl( $url ), $timeout );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( !is_string( $html ) || $html === '' || strlen( $html ) > self::MAX_HTML_BYTES ) {
			return null;
		}
		return $html;
	}

	private function defaultTransport(): callable {
		return static function ( string $url, float $timeout ): string {
			$http = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory()
				->create(
					$url,
					[
						'timeout' => (int)max( 1, (int)ceil( $timeout ) ),
						'connectTimeout' => (int)max( 1, min( 10, (int)ceil( $timeout ) ) ),
						'rejectLocalUrls' => true,
					],
					__METHOD__
				);
			$status = $http->execute();
			if ( !$status->isOK() ) {
				throw new \RuntimeException( 'HTTP ' . $status->getMessage()->getKey() );
			}
			$body = $http->getContent();
			return is_string( $body ) ? $body : '';
		};
	}
}
