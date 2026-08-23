<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\CrossrefProvider;
use EmbeddableContent\Fetch\GitHubSoftwareProvider;
use EmbeddableContent\Fetch\OpenAlexProvider;
use EmbeddableContent\Fetch\OpenLibraryProvider;
use EmbeddableContent\Fetch\OrcidProvider;
use EmbeddableContent\Fetch\ProviderClient;
use EmbeddableContent\Fetch\WikidataEntityProvider;
use EmbeddableContent\Fetch\WikidataPersonProvider;
use EmbeddableContent\Fetch\WikidataSoftwareProvider;
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

	public function testWorkAbstractByDoiPrefersOpenAlex(): void {
		$http = new FakeHttpClient();
		// Both Crossref and OpenAlex answer; OpenAlex (the higher-coverage
		// provider) must win — the cascade iterates doiProviders reversed.
		$http->onJson( '/works/10.1000%2Fxyz', [
			'message' => [ 'title' => [ 'Crossref one' ], 'abstract' => '<jats:p>Crossref abstract.</jats:p>' ],
		] );
		$http->onJson( '/works/doi:', [
			'id' => 'https://openalex.org/W1', 'title' => 'OpenAlex one',
			'abstract_inverted_index' => [ 'OpenAlex' => [ 0 ], 'abstract' => [ 1 ], '.' => [ 2 ] ],
			'keywords' => [ 'oa' ],
		] );
		$client = new ProviderClient( [], [], [], [ new CrossrefProvider( $http ), new OpenAlexProvider( $http ) ] );

		$data = $client->workAbstractByDoi( '10.1000/xyz' );

		$this->assertSame( 'OpenAlex abstract.', $data['abstract'] );
		$this->assertSame( 'oa', $data['keywords'] );
		$this->assertSame( 'openalex', $data['source'] );
	}

	public function testWorkAbstractByDoiFallsBackToCrossref(): void {
		$http = new FakeHttpClient();
		$http->onJson( '/works/doi:', [ 'id' => 'https://openalex.org/W1', 'title' => 'No abstract here' ] );
		$http->onJson( '/works/10.1000%2Fxyz', [
			'message' => [ 'title' => [ 'Crossref one' ], 'abstract' => '<jats:p>Direct Crossref abstract.</jats:p>' ],
		] );
		$client = new ProviderClient( [], [], [], [ new CrossrefProvider( $http ), new OpenAlexProvider( $http ) ] );

		$data = $client->workAbstractByDoi( '10.1000/xyz' );

		$this->assertSame( 'Direct Crossref abstract.', $data['abstract'] );
		$this->assertSame( 'crossref', $data['source'] );
	}

	public function testWorkAbstractByDoiEmptyWhenNothingAvailable(): void {
		$http = new FakeHttpClient();
		$http->onJson( '/works/doi:', [ 'id' => 'https://openalex.org/W1', 'title' => 'No abstract here' ] );
		$http->onJson( '/works/10.1000%2Fxyz', [ 'message' => [ 'title' => [ 'No abstract' ] ] ] );
		$client = new ProviderClient( [], [], [], [ new CrossrefProvider( $http ), new OpenAlexProvider( $http ) ] );

		$this->assertSame(
			[ 'abstract' => null, 'keywords' => null, 'source' => null ],
			$client->workAbstractByDoi( '10.1000/xyz' )
		);
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

	public function testByViafAndByIsniUseTheHub(): void {
		$http = new FakeHttpClient();
		// SPARQL lookup answers both P214 and P213 with the same Q42.
		$http->onJson( 'query.wikidata.org', [
			'head' => [ 'vars' => [ 'item' ] ],
			'results' => [ 'bindings' => [ [ 'item' => [ 'type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q42' ] ] ] ],
		] );
		$http->onJson( 'action=wbgetentities', [
			'entities' => [ 'Q42' => [ 'labels' => [ 'en' => [ 'value' => 'Douglas Adams' ] ], 'claims' => [] ] ],
		] );
		$hub = new WikidataPersonProvider( $http );
		$client = new ProviderClient( [], [], [], [], [], [], $hub );

		$viaf = $client->byViaf( '113230702' );
		$this->assertCount( 1, $viaf->records );
		$this->assertSame( 'Q42', $viaf->records[0]->wikidataId );

		$isni = $client->byIsni( '0000 0001 2345 6789' );
		$this->assertCount( 1, $isni->records );
		$this->assertSame( 'Q42', $isni->records[0]->wikidataId );
	}

	public function testByViafWarnsWithoutHub(): void {
		$client = new ProviderClient();

		$result = $client->byViaf( '113230702' );

		$this->assertSame( [], $result->records );
		$this->assertNotSame( [], $result->warnings );
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

	public function testSearchWorksByAuthorNameCollectsAcrossProviders(): void {
		$http = new FakeHttpClient();
		// Wikidata (SPARQL text mode) + Crossref both return a work by the
		// author; Open Library / OpenAlex return none.
		$http->onJson( 'query.wikidata.org', [
			'head' => [ 'vars' => [] ],
			'results' => [ 'bindings' => [
				[
					'work' => [ 'type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q123' ],
					'workLabel' => [ 'type' => 'literal', 'value' => 'A Work by Feynman' ],
				],
			] ],
		] );
		$http->onJson( '/search.json', [ 'docs' => [] ] );
		// NOTE: register the Crossref needle BEFORE '/works' — the Crossref
		// URL (api.crossref.org/works?…) also contains '/works', and fake
		// routes match by substring in registration order.
		$http->onJson( 'query.author', [ 'message' => [ 'items' => [
			[ 'title' => [ 'A Work by Feynman' ], 'DOI' => '10.1000/feynman' ],
		] ] ] );
		$http->onJson( '/works', [ 'results' => [] ] );
		$client = new ProviderClient(
			[],
			[],
			[ new WikidataWorkProvider( $http ), new OpenLibraryProvider( $http ), new OpenAlexProvider( $http ), new CrossrefProvider( $http ) ],
			[],
			[],
			[]
		);

		$result = $client->searchWorksByAuthorName( 'Richard Feynman' );

		// Different authority ids (Q123 vs DOI) — both collected, Wikidata first.
		$this->assertCount( 2, $result->records );
		$this->assertSame( 'Q123', $result->records[0]->wikidataId );
		$this->assertSame( '10.1000/feynman', $result->records[1]->doi );
		$this->assertSame( [], $result->warnings );
	}

	public function testSearchWorksByAuthorEntitiesOnlyCallsTheWikidataHub(): void {
		$http = new FakeHttpClient();
		$http->onJson( 'query.wikidata.org', [
			'head' => [ 'vars' => [] ],
			'results' => [ 'bindings' => [
				[
					'work' => [ 'type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q123' ],
					'workLabel' => [ 'type' => 'literal', 'value' => 'A Work by Feynman' ],
				],
			] ],
		] );
		// The non-hub providers return [] WITHOUT HTTP calls — no extra fake
		// routes registered, so any accidental request would throw.
		$client = new ProviderClient(
			[],
			[],
			[ new WikidataWorkProvider( $http ), new OpenLibraryProvider( $http ), new OpenAlexProvider( $http ), new CrossrefProvider( $http ) ],
			[],
			[],
			[]
		);

		$result = $client->searchWorksByAuthorEntities( [ 'Q392' ] );

		$this->assertCount( 1, $result->records );
		$this->assertSame( 'Q123', $result->records[0]->wikidataId );
		$this->assertSame( 1, count( $http->calls ) );
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

	public function testSearchSoftwareCollectsAndDedupesAcrossProviders(): void {
		$http = new FakeHttpClient();
		// Wikidata + GitHub both return the same program → dedupe by
		// wikidataId wins (Wikidata first in the cascade).
		$http->onJson( 'action=wbsearchentities', [
			'search' => [ [ 'id' => 'Q1202003', 'label' => 'Flameshot', 'description' => 'screenshot software' ] ],
		] );
		$http->onJson( '/search/repositories?q=flameshot', [
			'items' => [
				[
					'full_name' => 'flameshot-org/flameshot',
					'name' => 'flameshot',
					'description' => 'Powerful yet simple to use screenshot software',
					'html_url' => 'https://github.com/flameshot-org/flameshot',
					'homepage' => 'https://flameshot.org',
					'language' => 'C++',
					'license' => [ 'spdx_id' => 'GPL-3.0' ],
				],
			],
		] );
		$wikidataSoftware = new WikidataSoftwareProvider( $http );
		$client = new ProviderClient(
			[], [], [], [], [], [],
			null, null, null,
			$wikidataSoftware,
			[ $wikidataSoftware, new GitHubSoftwareProvider( $http ) ]
		);

		$result = $client->searchSoftware( 'flameshot' );

		$this->assertCount( 2, $result->records );
		$this->assertSame( 'Flameshot', $result->records[0]->label );
		$this->assertSame( 'Q1202003', $result->records[0]->wikidataId );
		$this->assertSame( 'flameshot-org/flameshot', $result->records[1]->githubFullName );
		$this->assertSame( [], $result->warnings );
	}

	public function testHarvestSoftwareHitsTheWikidataHub(): void {
		$http = new FakeHttpClient();
		$http->onJson( 'action=wbgetentities', [
			'entities' => [
				'Q1202003' => [
					'labels' => [ 'en' => [ 'value' => 'Flameshot' ] ],
					'claims' => [ 'P856' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => 'https://flameshot.org' ] ] ] ] ],
				],
			],
		] );
		$client = new ProviderClient(
			[], [], [], [], [], [],
			null, null, null,
			new WikidataSoftwareProvider( $http )
		);

		$result = $client->harvestSoftware( 'Q1202003' );

		$this->assertCount( 1, $result->records );
		$this->assertSame( 'Flameshot', $result->records[0]->label );
		$this->assertSame( 'https://flameshot.org', $result->records[0]->website );
	}
}
