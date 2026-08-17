<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\EntityRecord;
use EmbeddableContent\Fetch\WikidataEntityProvider;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class WikidataEntityProviderTest extends TestCase {

	public function testSearchMapsDescriptions(): void {
		$http = ( new FakeHttpClient() )->onJson( 'action=wbsearchentities', [
			'search' => [
				[ 'id' => 'Q11698', 'label' => 'Tartu Observatory', 'description' => 'observatory in Estonia' ],
			],
		] );
		$provider = new WikidataEntityProvider( $http );

		$records = $provider->searchByName( 'tartu observatory' );

		$this->assertCount( 1, $records );
		$this->assertSame( 'Tartu Observatory', $records[0]->label );
		$this->assertSame( 'observatory in Estonia', $records[0]->description );
		$this->assertSame( 'Q11698', $records[0]->wikidataId );
	}

	public function testByWikidataIdHarvestsClassHints(): void {
		$http = ( new FakeHttpClient() )->onJson( 'action=wbgetentities', [
			'entities' => [
				'Q1085' => [
					'labels' => [ 'en' => [ 'value' => 'The Beatles' ] ],
					'descriptions' => [ 'en' => [ 'value' => 'English rock band' ] ],
					'claims' => [
						'P31' => [
							[ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q215380' ] ] ] ],
							[ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q16334295' ] ] ] ],
						],
					],
				],
			],
		] );
		$provider = new WikidataEntityProvider( $http );

		$record = $provider->byWikidataId( 'Q1085' );

		$this->assertInstanceOf( EntityRecord::class, $record );
		$this->assertSame( 'The Beatles', $record->label );
		$this->assertSame( [ 'Q215380', 'Q16334295' ], $record->classWikidataIds );
	}
}
