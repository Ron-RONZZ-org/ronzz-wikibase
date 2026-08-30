<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\NominatimProvider;
use PHPUnit\Framework\TestCase;

/**
 * OpenStreetMap Nominatim label→OSM auto-match (osm-places): the top
 * search result becomes the node|way|relation/<id> prefill behind the
 * fetch-match-confirm banner.
 *
 * @covers \EmbeddableContent\Fetch\NominatimProvider
 * @license GPL-2.0-or-later
 */
class NominatimProviderTest extends TestCase {

	private function provider( FakeHttpClient $http, float $timeout = 6.0 ): NominatimProvider {
		return new NominatimProvider( $http, $timeout );
	}

	public function testTopMatchForLabelReturnsCanonicalId(): void {
		$http = new FakeHttpClient();
		$http->onJson( 'nominatim.openstreetmap.org/search', [
			[
				'osm_type' => 'relation',
				'osm_id' => 175905,
				'display_name' => 'New York, United States',
				'importance' => 0.9,
			],
			[
				'osm_type' => 'node',
				'osm_id' => 5128581,
				'display_name' => 'New York, Texas, United States',
				'importance' => 0.4,
			],
		] );
		$match = $this->provider( $http )->topMatchForLabel( 'New York City' );
		$this->assertSame( 'relation', $match['osmType'] );
		$this->assertSame( 175905, $match['osmId'] );
		$this->assertSame( 'New York, United States', $match['displayName'] );

		$call = $http->calls[0];
		$this->assertSame(
			'https://nominatim.openstreetmap.org/search?q=New+York+City&format=jsonv2&limit=5&addressdetails=0&accept-language=en',
			$call['url']
		);
		$this->assertSame( 'New York City', $call['params']['q'] );
		$this->assertSame( 'jsonv2', $call['params']['format'] );
		$this->assertSame( '5', (string)$call['params']['limit'] );
		$this->assertSame( 'en', $call['params']['accept-language'] );
	}

	public function testSkipsRowsWithoutUsableOsmData(): void {
		$http = new FakeHttpClient();
		$http->onJson( 'nominatim.openstreetmap.org/search', [
			[ 'osm_type' => 'unknown', 'osm_id' => 1, 'display_name' => 'x' ],
			[ 'osm_type' => 'node', 'osm_id' => 0, 'display_name' => 'x' ],
			[ 'osm_type' => 'node', 'osm_id' => 5, 'display_name' => '' ],
		] );
		$this->assertNull( $this->provider( $http )->topMatchForLabel( 'Nowhere' ) );
	}

	public function testEmptyLabelReturnsNullWithoutRequest(): void {
		$http = new FakeHttpClient();
		$this->assertNull( $this->provider( $http )->topMatchForLabel( '   ' ) );
		$this->assertSame( [], $http->calls );
	}

	public function testProviderExceptionPropagatesForCallerHandling(): void {
		$http = new FakeHttpClient();
		$http->onError( 'nominatim.openstreetmap.org/search', 'boom' );
		$this->expectException( \EmbeddableContent\Fetch\ProviderException::class );
		$this->provider( $http )->topMatchForLabel( 'Cambridge' );
	}
}
