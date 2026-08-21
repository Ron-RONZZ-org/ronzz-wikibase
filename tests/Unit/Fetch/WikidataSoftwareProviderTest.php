<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\SoftwareRecord;
use EmbeddableContent\Fetch\WikidataSoftwareProvider;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class WikidataSoftwareProviderTest extends TestCase {

	public function testSearchMapsDescriptions(): void {
		$http = ( new FakeHttpClient() )->onJson( 'action=wbsearchentities', [
			'search' => [
				[ 'id' => 'Q1202003', 'label' => 'Flameshot', 'description' => 'screenshot software' ],
			],
		] );
		$provider = new WikidataSoftwareProvider( $http );

		$records = $provider->searchByName( 'flameshot' );

		$this->assertCount( 1, $records );
		$this->assertSame( 'Flameshot', $records[0]->label );
		$this->assertSame( 'screenshot software', $records[0]->description );
		$this->assertSame( 'Q1202003', $records[0]->wikidataId );
		$this->assertSame( 'wikidata', $records[0]->provider );
	}

	public function testByWikidataIdHarvestsSoftwareFacts(): void {
		$http = ( new FakeHttpClient() )
			// Full harvest — registered first (most specific needle).
			->onJson( 'action=wbgetentities&ids=Q1202003', [
				'entities' => [
					'Q1202003' => [
						'labels' => [ 'en' => [ 'value' => 'Flameshot' ] ],
						'descriptions' => [ 'en' => [ 'value' => 'screenshot software' ] ],
						'claims' => [
							'P31' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q7397' ] ] ] ] ],
							'P856' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => 'https://flameshot.org' ] ] ] ],
							'P1324' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => 'https://github.com/flameshot-org/flameshot' ] ] ] ],
							'P178' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q5583113' ] ] ] ] ],
							'P275' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q7603' ] ] ] ] ],
							'P306' => [
								[ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q388' ] ] ] ],
								[ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q1406' ] ] ] ],
							],
							'P277' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q2407' ] ] ] ] ],
							'P348' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => '12.1.0' ] ] ] ],
							'P1262' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => [ 'id' => 'Q581650' ] ] ] ] ],
						],
					],
				],
			] )
			// Nested label resolution for the item-typed values.
			->onJson( 'action=wbgetentities&ids=Q5583113%7CQ7603%7CQ388%7CQ1406%7CQ2407%7CQ581650', [
				'entities' => [
					'Q5583113' => [ 'labels' => [ 'en' => [ 'value' => 'Flameshot contributors' ] ] ],
					'Q7603' => [ 'labels' => [ 'en' => [ 'value' => 'GNU General Public License v3.0' ] ] ],
					'Q388' => [ 'labels' => [ 'en' => [ 'value' => 'Linux' ] ] ],
					'Q1406' => [ 'labels' => [ 'en' => [ 'value' => 'Windows' ] ] ],
					'Q2407' => [ 'labels' => [ 'en' => [ 'value' => 'C++' ] ] ],
					'Q581650' => [ 'labels' => [ 'en' => [ 'value' => 'graphical user interface' ] ] ],
				],
			] );
		$provider = new WikidataSoftwareProvider( $http );

		$record = $provider->byWikidataId( 'Q1202003' );

		$this->assertInstanceOf( SoftwareRecord::class, $record );
		$this->assertSame( 'Flameshot', $record->label );
		$this->assertSame( 'https://flameshot.org', $record->website );
		$this->assertSame( 'https://github.com/flameshot-org/flameshot', $record->sourceRepository );
		$this->assertSame( 'Flameshot contributors', $record->developer );
		$this->assertSame( 'Q5583113', $record->developerWikidataId );
		$this->assertSame( 'GNU General Public License v3.0', $record->license );
		$this->assertSame( 'Q7603', $record->licenseWikidataId );
		$this->assertSame( 'Linux, Windows', $record->operatingSystem );
		$this->assertSame( 'C++', $record->programmingLanguage );
		$this->assertSame( 'Q2407', $record->programmingLanguageWikidataId );
		$this->assertSame( '12.1.0', $record->latestVersion );
		$this->assertSame( 'graphical user interface', $record->userInterface );
		$this->assertSame( [ 'Q7397' ], $record->classWikidataIds );
	}

	public function testByWikidataIdReturnsNullForUnknownEntity(): void {
		$http = ( new FakeHttpClient() )->onJson( 'action=wbgetentities', [ 'entities' => [] ] );
		$provider = new WikidataSoftwareProvider( $http );

		$this->assertNull( $provider->byWikidataId( 'Q999999999' ) );
	}
}
