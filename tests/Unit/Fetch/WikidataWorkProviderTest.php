<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\WorkRecord;
use EmbeddableContent\Fetch\WikidataWorkProvider;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class WikidataWorkProviderTest extends TestCase {

	public function testByWikidataIdHarvestsCitationMetadata(): void {
		$http = ( new FakeHttpClient() )
			->onJson( 'action=wbgetentities&ids=Q571&props=labels%7Cdescriptions%7Cclaims', [
				'entities' => [
					'Q571' => [
						'labels' => [ 'en' => [ 'value' => 'The Old Man and the Sea' ] ],
						'claims' => [
							'P1433' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q999' ] ] ] ] ],
							'P123' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q888' ] ] ] ] ],
							'P478' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => '42' ] ] ] ],
							'P433' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => '3' ] ] ] ],
							'P304' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => '100-115' ] ] ] ],
							'P356' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => '10.1000/xyz' ] ] ] ],
							'P212' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => '978-0-00-000000-0' ] ] ] ],
							'P10283' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => 'W2000000001' ] ] ] ],
							'P698' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => '12345678' ] ] ] ],
							'P577' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'time' => '+2019-05-01T00:00:00Z' ] ] ] ] ],
						],
					],
				],
			] )
			->onJson( 'action=wbgetentities&ids=Q999%7CQ888&props=labels', [
				'entities' => [
					'Q999' => [ 'labels' => [ 'en' => [ 'value' => 'Nature' ] ] ],
					'Q888' => [ 'labels' => [ 'en' => [ 'value' => 'Springer' ] ] ],
				],
			] );
		$provider = new WikidataWorkProvider( $http );

		$record = $provider->byWikidataId( 'Q571' );

		$this->assertInstanceOf( WorkRecord::class, $record );
		$this->assertSame( 'The Old Man and the Sea', $record->title );
		$this->assertSame( 'Nature', $record->containerTitle );
		$this->assertSame( 'Springer', $record->publisher );
		$this->assertSame( '42', $record->volume );
		$this->assertSame( '3', $record->issue );
		$this->assertSame( '100-115', $record->pages );
		$this->assertSame( '10.1000/xyz', $record->doi );
		$this->assertSame( '978-0-00-000000-0', $record->isbn );
		$this->assertSame( 'W2000000001', $record->openalexId );
		$this->assertSame( '12345678', $record->pubmedId );
		$this->assertSame( 2019, $record->issuedYear );
	}

	public function testByDoiResolvesViaSparql(): void {
		$http = ( new FakeHttpClient() )
			->onJson( 'query.wikidata.org', [
				'head' => [ 'vars' => [ 'item' ] ],
				'results' => [ 'bindings' => [ [ 'item' => [ 'type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q123' ] ] ] ],
			] )
			->onJson( 'action=wbgetentities&ids=Q123&props=labels%7Cdescriptions%7Cclaims', [
				'entities' => [ 'Q123' => [ 'labels' => [ 'en' => [ 'value' => 'Some Article' ] ], 'claims' => [] ] ],
			] )
			->onJson( 'action=wbgetentities&props=labels', [ 'entities' => [] ] );
		$provider = new WikidataWorkProvider( $http );

		$record = $provider->byDoi( '10.1000/xyz' );

		$this->assertNotNull( $record );
		$this->assertSame( 'Some Article', $record->title );
		$this->assertSame( 'Q123', $record->wikidataId );
		$this->assertStringContainsString( 'wdt:P356', $http->calls[0]['params']['query'] ?? '' );
	}
}
