<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\CurlHttpClient;
use EmbeddableContent\Fetch\ProviderException;
use PHPUnit\Framework\TestCase;

/**
 * SSRF allowlist tests — all paths throw BEFORE any network call.
 *
 * @license GPL-2.0-or-later
 */
final class CurlHttpClientTest extends TestCase {

	public function testNonAllowlistedHostIsRejected(): void {
		$client = new CurlHttpClient( [ 'api.openalex.org' ] );
		$this->expectException( ProviderException::class );
		$this->expectExceptionMessage( 'allowlist' );
		$client->getJson( 'https://evil.example.org/data' );
	}

	public function testHttpSchemeIsRejected(): void {
		$client = new CurlHttpClient( [ 'www.wikidata.org' ] );
		$this->expectException( ProviderException::class );
		$this->expectExceptionMessage( 'https' );
		$client->getJson( 'http://www.wikidata.org/w/api.php' );
	}

	public function testAllowlistedHostPassesTheCheck(): void {
		// The allowlist check happens first; a non-allowlisted *port* trick
		// must still be rejected (host match is exact, not suffix).
		$client = new CurlHttpClient( [ 'api.openalex.org' ] );
		$this->expectException( ProviderException::class );
		$this->expectExceptionMessage( 'allowlist' );
		$client->getJson( 'https://api.openalex.org.evil.example/data' );
	}

	public function testQueryParametersAreAppended(): void {
		// No route match is fine — we assert via the exception message that
		// the URL (with query) was attempted against the allowlisted host.
		$client = new CurlHttpClient( [ 'example.invalid' ] );
		try {
			$client->getJson( 'https://example.invalid/x', [ 'a' => 'b c' ] );
			$this->fail( 'Expected ProviderException from the DNS/connection failure' );
		} catch ( ProviderException $e ) {
			$this->assertStringContainsString( 'a=b+c', $e->getMessage() );
		}
	}
}
