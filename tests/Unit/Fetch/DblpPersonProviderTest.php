<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\DblpPersonProvider;
use EmbeddableContent\Fetch\PersonRecord;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class DblpPersonProviderTest extends TestCase {

	public function testSearchWithSparqlEnrichment(): void {
		$http = ( new FakeHttpClient() )
			->onJson( 'dblp.org/search/author/api', [
				'result' => [
					'hits' => [
						'hit' => [
							[ 'info' => [ 'author' => 'Mathias Neumann Andersen', 'url' => 'https://dblp.org/pid/214/3621' ] ],
						],
					],
				],
			] )
			->onJson( 'sparql.dblp.org', [
				'head' => [ 'vars' => [ 'wikidata' ] ],
				'results' => [ 'bindings' => [ [ 'wikidata' => [ 'type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q12345' ] ] ] ],
			] );
		$provider = new DblpPersonProvider( $http );

		$records = $provider->searchByName( 'neumann' );

		$this->assertCount( 1, $records );
		$this->assertInstanceOf( PersonRecord::class, $records[0] );
		$this->assertSame( 'Mathias Neumann Andersen', $records[0]->label );
		$this->assertSame( 'Q12345', $records[0]->wikidataId );
		$this->assertSame( 'dblp', $records[0]->provider );
		$this->assertSame( 'https://dblp.org/pid/214/3621', $records[0]->providerId );
	}

	public function testEnrichmentFailureDegradesToNameOnlyRecord(): void {
		$http = ( new FakeHttpClient() )
			->onJson( 'dblp.org/search/author/api', [
				'result' => [ 'hits' => [ 'hit' => [ [ 'info' => [ 'author' => 'Jane Doe', 'url' => 'https://dblp.org/pid/1/2' ] ] ] ] ],
			] )
			->onError( 'sparql.dblp.org', 'dblp SPARQL down' );
		$provider = new DblpPersonProvider( $http );

		$records = $provider->searchByName( 'jane doe' );

		$this->assertCount( 1, $records );
		$this->assertSame( 'Jane Doe', $records[0]->label );
		$this->assertNull( $records[0]->wikidataId );
	}

	public function testByOrcidReturnsNull(): void {
		$provider = new DblpPersonProvider( new FakeHttpClient() );
		$this->assertNull( $provider->byOrcid( '0000-0001-2345-6789' ) );
	}
}
