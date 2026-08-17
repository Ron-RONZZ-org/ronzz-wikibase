<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\CrossrefProvider;
use EmbeddableContent\Fetch\OpenAlexProvider;
use EmbeddableContent\Fetch\OpenLibraryProvider;
use EmbeddableContent\Fetch\OrcidProvider;
use EmbeddableContent\Fetch\ProviderClient;
use EmbeddableContent\Fetch\WikidataEntityProvider;
use EmbeddableContent\Fetch\WikidataPersonProvider;
use EmbeddableContent\Fetch\WikidataWorkProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cascade behavior: ordering, dedupe, warnings, hub harvest.
 *
 * @license GPL-2.0-or-later
 */
final class ProviderClientTest extends TestCase {

	private function personFixtures( FakeHttpClient $http ): array {
		// Two providers return the SAME person (same wikidataId) → dedupe.
		$http->onJson( 'action=wbsearchentities', [
			'search' => [ [ 'id' => 'Q42', 'label' => 'Douglas Adams' ] ],
		] );
		$http->onJson( '/authors', [
			'results' => [
				[ 'id' => 'https://openalex.org/A1', 'display_name' => 'Douglas Adams', 'ids' => [ 'wikidata' => 'https://www.wikidata.org/wiki/Q42' ] ],
			],
		] );
		$http->onJson( 'dblp.org/search/author/api', [ 'result' => [ 'hits' => [ 'hit' => [] ] ] ] );
		$http->onJson( 'expanded-search', [ 'expanded-result' => [] ] );

		return [
			new WikidataPersonProvider( $http ),
			new OpenAlexProvider( $http ),
			new OrcidProvider( $http ),
		];
	}

	public function testSearchPersonsCollectsAndDedupesByAuthorityId(): void {
		$http = new FakeHttpClient();
		$client = new ProviderClient( $this->personFixtures( $http ) );

		$result = $client->searchPersons( 'douglas adams' );

		$this->assertCount( 1, $result->records );
		$this->assertSame( 'Q42', $result->records[0]->wikidataId );
		$this->assertSame( 'Douglas Adams', $result->records[0]->label );
		$this->assertSame( [], $result->warnings );
	}

	public function testProviderFailureBecomesWarningNotFatal(): void {
		$http = new FakeHttpClient();
		$http->onJson( 'action=wbsearchentities', [ 'search' => [] ] );
		$http->onError( '/authors', 'openalex timeout' );
		$http->onJson( 'dblp.org/search/author/api', [ 'result' => [ 'hits' => [ 'hit' => [] ] ] ] );
		$http->onJson( 'expanded-search', [ 'expanded-result' => [] ] );
		$client = new ProviderClient( [
			new WikidataPersonProvider( $http ),
			new OpenAlexProvider( $http ),
			new OrcidProvider( $http ),
		] );

		$result = $client->searchPersons( 'nobody' );

		$this->assertCount( 1, $result->warnings );
		$this->assertStringContainsString( 'openalex', $result->warnings[0] );
	}

	public function testByIdentifierStopsAtFirstHit(): void {
		$http = new FakeHttpClient();
		$http->onJson( '/works/10.1000%2Fxyz', [
			'message' => [ 'title' => [ 'Via Crossref' ], 'DOI' => '10.1000/xyz', 'issued' => [ 'date-parts' => [ [ 2020 ] ] ] ],
		] );
		$client = new ProviderClient( [], [], [], [ new CrossrefProvider( $http ) ] );

		$result = $client->byDoi( '10.1000/xyz' );

		$this->assertCount( 1, $result->records );
		$this->assertSame( 'Via Crossref', $result->records[0]->title );
		$this->assertSame( 1, count( $http->calls ) ); // no fallback calls made
	}

	public function testByIdentifierReturnsEmptyWithWarningsWhenAllProvidersFail(): void {
		$http = new FakeHttpClient();
		$http->onError( '/works/10.1000%2Fxyz', 'crossref down' );
		$client = new ProviderClient( [], [], [], [ new CrossrefProvider( $http ) ] );

		$result = $client->byDoi( '10.1000/xyz' );

		$this->assertSame( [], $result->records );
		$this->assertCount( 1, $result->warnings );
	}

	public function testHarvestPersonUsesTheHub(): void {
		$http = new FakeHttpClient();
		$http->onJson( 'action=wbgetentities', [
			'entities' => [ 'Q42' => [ 'labels' => [ 'en' => [ 'value' => 'Douglas Adams' ] ], 'claims' => [] ] ],
		] );
		$hub = new WikidataPersonProvider( $http );
		$client = new ProviderClient( [], [], [], [], [], [], $hub );

		$result = $client->harvestPerson( 'Q42' );

		$this->assertCount( 1, $result->records );
		$this->assertSame( 'Douglas Adams', $result->records[0]->label );
	}

	public function testHarvestWorkAndEntity(): void {
		$http = new FakeHttpClient();
		$http->onJson( 'action=wbgetentities', [
			'entities' => [
				'Q571' => [ 'labels' => [ 'en' => [ 'value' => 'A Book' ] ], 'claims' => [] ],
				'Q1085' => [ 'labels' => [ 'en' => [ 'value' => 'The Beatles' ] ], 'claims' => [] ],
			],
		] );
		$client = new ProviderClient(
			[],
			[],
			[],
			[],
			[],
			[],
			null,
			new WikidataWorkProvider( $http ),
			new WikidataEntityProvider( $http )
		);

		$this->assertSame( 'A Book', $client->harvestWork( 'Q571' )->records[0]->title );
		$this->assertSame( 'The Beatles', $client->harvestEntity( 'Q1085' )->records[0]->label );
	}

	public function testTitleSearchPrefersWikidataOverCrossref(): void {
		// #7 cascade table: title search = Wikidata → Open Library → OpenAlex → Crossref.
		$http = new FakeHttpClient();
		$http->onJson( 'action=wbsearchentities', [
			'search' => [ [ 'id' => 'Q123', 'label' => 'A Wikidata Work' ] ],
		] );
		$http->onJson( '/search.json', [ 'docs' => [ [ 'title' => 'An OpenLibrary Work', 'key' => '/books/OL1' ] ] ] );
		$http->onJson( '/works', [ 'results' => [] ] );
		$http->onJson( 'query.title', [ 'message' => [ 'items' => [] ] ] );

		$client = new ProviderClient(
			[],
			[],
			[ new WikidataWorkProvider( $http ), new OpenLibraryProvider( $http ), new OpenAlexProvider( $http ), new CrossrefProvider( $http ) ],
			[],
			[],
			[]
		);

		$result = $client->searchWorks( 'a title' );

		$this->assertCount( 2, $result->records );
		$this->assertSame( 'A Wikidata Work', $result->records[0]->title );
		$this->assertSame( 'An OpenLibrary Work', $result->records[1]->title );
	}

	public function testDefaultFactoryWiresTheCanonicalCascade(): void {
		$http = new FakeHttpClient();
		$client = ProviderClient::default( $http );

		// The default wiring resolves a DOI through Crossref first (no other
		// fake routes exist, so a non-Crossref call would throw).
		$http->onJson( '/works/10.1000%2Fxyz', [
			'message' => [ 'title' => [ 'Default wired' ], 'DOI' => '10.1000/xyz' ],
		] );
		$result = $client->byDoi( '10.1000/xyz' );
		$this->assertSame( 'Default wired', $result->records[0]->title );
	}
}
