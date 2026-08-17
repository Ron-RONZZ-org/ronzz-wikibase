<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\PersonRecord;
use EmbeddableContent\Fetch\WikidataPersonProvider;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class WikidataPersonProviderTest extends TestCase {

	public function testSearchByNameMapsResults(): void {
		$http = ( new FakeHttpClient() )->onJson( 'action=wbsearchentities', [
			'search' => [
				[ 'id' => 'Q42', 'label' => 'Douglas Adams', 'description' => 'English writer' ],
				[ 'id' => 'Q345', 'label' => 'Douglas Adams (astronomer)' ],
			],
		] );
		$provider = new WikidataPersonProvider( $http );

		$records = $provider->searchByName( 'douglas adams' );

		$this->assertCount( 2, $records );
		$this->assertSame( 'Douglas Adams', $records[0]->label );
		$this->assertSame( 'Q42', $records[0]->wikidataId );
		$this->assertSame( 'wikidata', $records[0]->provider );
	}

	public function testByWikidataIdHarvestsFullRecordWithNestedItemLabels(): void {
		$http = ( new FakeHttpClient() )
			// Full harvest (props=labels%7Cdescriptions%7Cclaims) — registered first.
			->onJson( 'action=wbgetentities&ids=Q42&props=labels%7Cdescriptions%7Cclaims', [
				'entities' => [
					'Q42' => [
						'labels' => [ 'en' => [ 'value' => 'Douglas Adams' ] ],
						'descriptions' => [ 'en' => [ 'value' => 'English writer and humorist' ] ],
						'claims' => [
							'P735' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q4925477' ] ] ] ] ],
							'P734' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q35150' ] ] ] ] ],
							'P496' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => '0000-0001-2345-6789' ] ] ] ],
							'P214' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => '113230702' ] ] ] ],
							'P213' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => '0000 0001 2345 6789' ] ] ] ],
						],
					],
				],
			] )
			// Nested label resolution (props=labels only).
			->onJson( 'action=wbgetentities&ids=Q4925477%7CQ35150&props=labels', [
				'entities' => [
					'Q4925477' => [ 'labels' => [ 'en' => [ 'value' => 'Douglas' ] ] ],
					'Q35150' => [ 'labels' => [ 'en' => [ 'value' => 'Adams' ] ] ],
				],
			] );
		$provider = new WikidataPersonProvider( $http );

		$record = $provider->byWikidataId( 'Q42' );

		$this->assertInstanceOf( PersonRecord::class, $record );
		$this->assertSame( 'Douglas Adams', $record->label );
		$this->assertSame( 'Douglas', $record->givenName );
		$this->assertSame( 'Adams', $record->familyName );
		$this->assertSame( '0000-0001-2345-6789', $record->orcid );
		$this->assertSame( '113230702', $record->viafId );
		$this->assertSame( '0000 0001 2345 6789', $record->isni );
		$this->assertSame( 'Q42', $record->wikidataId );
		// Exactly one nested fetch.
		$nested = array_filter(
			$http->calls,
			static fn ( array $c ): bool => strpos( $c['url'], 'props=labels' ) !== false
				&& strpos( $c['url'], 'descriptions' ) === false
		);
		$this->assertCount( 1, $nested );
	}

	public function testByOrcidUsesSparqlThenHarvest(): void {
		$http = ( new FakeHttpClient() )
			->onJson( 'query.wikidata.org', [
				'head' => [ 'vars' => [ 'item' ] ],
				'results' => [ 'bindings' => [ [ 'item' => [ 'type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q42' ] ] ] ],
			] )
			->onJson( 'action=wbgetentities&ids=Q42&props=labels%7Cdescriptions%7Cclaims', [
				'entities' => [ 'Q42' => [ 'labels' => [ 'en' => [ 'value' => 'Douglas Adams' ] ], 'claims' => [] ] ],
			] )
			->onJson( 'action=wbgetentities&props=labels', [ 'entities' => [] ] );
		$provider = new WikidataPersonProvider( $http );

		$record = $provider->byOrcid( '0000-0001-2345-6789' );

		$this->assertNotNull( $record );
		$this->assertSame( 'Douglas Adams', $record->label );
		$this->assertSame( 'Q42', $record->wikidataId );
	}

	public function testByWikidataIdFallsBackToSearchWhenLabelsAreWithheld(): void {
		// Wikidata withholds en/fr/eo labels for some entities (Q42 serves
		// only non-Latin labels) — harvest must fall back to wbsearchentities.
		$http = ( new FakeHttpClient() )
			->onJson( 'action=wbgetentities&ids=Q42&props=labels%7Cdescriptions%7Cclaims', [
				'entities' => [
					'Q42' => [
						'labels' => [ 'ar' => [ 'value' => 'دوغلاس آدمز' ] ],
						'claims' => [],
					],
				],
			] )
			->onJson( 'action=wbsearchentities', [
				'search' => [ [ 'id' => 'Q42', 'label' => 'Douglas Adams' ] ],
			] );
		$provider = new WikidataPersonProvider( $http );

		$record = $provider->byWikidataId( 'Q42' );

		$this->assertNotNull( $record );
		$this->assertSame( 'Douglas Adams', $record->label );
	}

	public function testByWikidataIdReturnsNullForUnknownEntity(): void {
		$http = ( new FakeHttpClient() )->onJson( 'action=wbgetentities', [ 'entities' => [] ] );
		$provider = new WikidataPersonProvider( $http );

		$this->assertNull( $provider->byWikidataId( 'Q999999' ) );
	}
}
