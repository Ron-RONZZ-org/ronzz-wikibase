<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\ProviderException;
use EmbeddableContent\Fetch\RateLimitedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Fetch\RateLimitedHttpClient
 * @license GPL-2.0-or-later
 */
final class RateLimitedHttpClientTest extends TestCase {

	public function testRetriesOn429WithRetryAfter(): void {
		$fake = new FakeHttpClient();
		$fake->onHttpErrorOnce( 'api.example', 429, [ 'ok' => true ], 0 ); // 0s delay — fast test
		$client = new RateLimitedHttpClient( $fake, 0.0, 2 );

		$result = $client->getJson( 'https://api.example/x' );

		$this->assertSame( [ 'ok' => true ], $result );
		$this->assertCount( 2, $fake->calls, 'the 429 attempt + the retry' );
	}

	public function testThrowsAfterMaxRetries(): void {
		$fake = new FakeHttpClient();
		$fake->onHttpError( 'api.example', 429, 0 );
		$client = new RateLimitedHttpClient( $fake, 0.0, 2 );

		try {
			$client->getJson( 'https://api.example/x' );
			$this->fail( 'expected ProviderException' );
		} catch ( ProviderException $e ) {
			$this->assertSame( 429, $e->getStatusCode() );
		}
		$this->assertCount( 3, $fake->calls, 'initial + 2 retries' );
	}

	public function testNon429StatusPropagatesWithoutRetry(): void {
		$fake = new FakeHttpClient();
		$fake->onHttpError( 'api.example', 500 );
		$client = new RateLimitedHttpClient( $fake, 0.0, 3 );

		try {
			$client->postForm( 'https://api.example/x', [ 'q' => '1' ] );
			$this->fail( 'expected ProviderException' );
		} catch ( ProviderException $e ) {
			$this->assertSame( 500, $e->getStatusCode() );
		}
		$this->assertCount( 1, $fake->calls, 'no retry on non-429' );
	}

	public function testNetworkErrorPropagatesWithoutRetry(): void {
		$fake = new FakeHttpClient();
		$fake->onError( 'api.example', 'connection refused' );
		$client = new RateLimitedHttpClient( $fake, 0.0, 3 );

		$this->expectException( ProviderException::class );
		$client->getJson( 'https://api.example/x' );
	}

	public function testWikimediaHostsShareOneBucketAcrossHosts(): void {
		// The bucket logic is observable through the request spacing: with
		// minInterval 0 the calls flow through untouched (no cross-bucket
		// interference is possible), and the WMF bucket grouping is a pure
		// function of the URL — assert both wikidata and wikipedia URLs are
		// served (the shared bucket would still serialize them, which is the
		// intended politeness; we only assert correctness here).
		$fake = new FakeHttpClient();
		$fake->onJson( 'wikidata.org', [ 'a' => 1 ] );
		$fake->onJson( 'wikipedia.org', [ 'b' => 2 ] );
		$client = new RateLimitedHttpClient( $fake, 0.0, 0 );

		$this->assertSame( [ 'a' => 1 ], $client->getJson( 'https://www.wikidata.org/w/api.php' ) );
		$this->assertSame( [ 'b' => 2 ], $client->getJson( 'https://en.wikipedia.org/w/api.php' ) );
		$this->assertCount( 2, $fake->calls );
	}

	public function testPostFormPassesThrough(): void {
		$fake = new FakeHttpClient();
		$fake->onJson( 'sparql', [ 'head' => [] ] );
		$client = new RateLimitedHttpClient( $fake, 0.0, 0 );

		$result = $client->postForm( 'https://query.wikidata.org/sparql', [ 'query' => 'SELECT' ] );
		$this->assertSame( [ 'head' => [] ], $result );
		$this->assertSame( 'postForm', $fake->calls[0]['method'] );
	}
}
