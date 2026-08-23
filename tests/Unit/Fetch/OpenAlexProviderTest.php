<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\OpenAlexProvider;
use EmbeddableContent\Fetch\PersonRecord;
use EmbeddableContent\Fetch\WorkRecord;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class OpenAlexProviderTest extends TestCase {

	public function testAuthorSearchMapsOrcidAndWikidata(): void {
		$http = ( new FakeHttpClient() )->onJson( '/authors', [
			'results' => [
				[
					'id' => 'https://openalex.org/A5023888391',
					'display_name' => 'Jason Priem',
					'orcid' => 'https://orcid.org/0000-0001-6187-6610',
					'ids' => [ 'wikidata' => 'https://www.wikidata.org/wiki/Q58065621' ],
				],
			],
		] );
		$provider = new OpenAlexProvider( $http );

		$records = $provider->searchByName( 'jason priem' );

		$this->assertCount( 1, $records );
		$this->assertInstanceOf( PersonRecord::class, $records[0] );
		$this->assertSame( 'Jason Priem', $records[0]->label );
		$this->assertSame( '0000-0001-6187-6610', $records[0]->orcid );
		$this->assertSame( 'A5023888391', $records[0]->openalexId );
		$this->assertSame( 'Q58065621', $records[0]->wikidataId );
		$this->assertSame( 'openalex', $records[0]->provider );
	}

	public function testByOrcidDirect(): void {
		$http = ( new FakeHttpClient() )->onJson( 'authors/orcid:', [
			'id' => 'https://openalex.org/A5023888391',
			'display_name' => 'Jason Priem',
			'orcid' => 'https://orcid.org/0000-0001-6187-6610',
			'ids' => [],
		] );
		$provider = new OpenAlexProvider( $http );

		$record = $provider->byOrcid( '0000-0001-6187-6610' );

		$this->assertNotNull( $record );
		$this->assertSame( 'Jason Priem', $record->label );
	}

	public function testWorkSearchMapsFullMetadata(): void {
		$http = ( new FakeHttpClient() )->onJson( '/works', [
			'results' => [
				[
					'id' => 'https://openalex.org/W2741809807',
					'doi' => 'https://doi.org/10.1371/journal.pbio.2001414',
					'title' => 'Identifiers for the 21st century',
					'publication_date' => '2017-04-06',
					'publisher' => 'Public Library of Science',
					'primary_location' => [ 'source' => [ 'display_name' => 'PLOS Biology' ] ],
					'biblio' => [ 'volume' => '15', 'issue' => '4', 'first_page' => 'e2001414' ],
					'ids' => [ 'pmid' => 'https://pubmed.ncbi.nlm.nih.gov/28379999/' ],
				],
			],
		] );
		$provider = new OpenAlexProvider( $http );

		$records = $provider->searchByTitle( 'identifiers for the 21st century' );

		$this->assertCount( 1, $records );
		$this->assertInstanceOf( WorkRecord::class, $records[0] );
		$this->assertSame( 'Identifiers for the 21st century', $records[0]->title );
		$this->assertSame( 'PLOS Biology', $records[0]->containerTitle );
		$this->assertSame( 'Public Library of Science', $records[0]->publisher );
		$this->assertSame( '15', $records[0]->volume );
		$this->assertSame( '4', $records[0]->issue );
		$this->assertSame( 'e2001414', $records[0]->pages );
		$this->assertSame( '10.1371/journal.pbio.2001414', $records[0]->doi );
		$this->assertSame( 'W2741809807', $records[0]->openalexId );
		$this->assertSame( '28379999', $records[0]->pubmedId );
		$this->assertSame( 2017, $records[0]->issuedYear );
	}

	public function testByIsbnReturnsNull(): void {
		$provider = new OpenAlexProvider( new FakeHttpClient() );
		$this->assertNull( $provider->byIsbn( '9780684801223' ) );
	}

	public function testWorkSearchByAuthorNameFiltersByAuthor(): void {
		$http = ( new FakeHttpClient() )->onJson( '/works', [
			'results' => [
				[ 'id' => 'https://openalex.org/W2741809807', 'title' => 'The Feynman Lectures on Physics' ],
			],
		] );
		$provider = new OpenAlexProvider( $http );

		$records = $provider->searchByAuthorName( 'Richard Feynman' );

		$this->assertCount( 1, $records );
		$this->assertSame( 'The Feynman Lectures on Physics', $records[0]->title );
		$this->assertSame( 'author.search:Richard Feynman', $http->calls[0]['params']['filter'] ?? null );
	}

	public function testWorkSearchByAuthorNameNarrowsWithTitle(): void {
		$http = ( new FakeHttpClient() )->onJson( '/works', [ 'results' => [] ] );
		$provider = new OpenAlexProvider( $http );

		$provider->searchByAuthorName( 'Richard Feynman', 'Lectures' );

		$this->assertSame( 'Lectures', $http->calls[0]['params']['search'] ?? null );
	}

	public function testSearchByAuthorEntitiesReturnsEmpty(): void {
		// OpenAlex filters by A-ids, not Wikidata Q-ids — hub covers entities.
		$provider = new OpenAlexProvider( new FakeHttpClient() );
		$this->assertSame( [], $provider->searchByAuthorEntities( [ 'Q42' ] ) );
	}

	public function testWorkSearchMapsAbstractAndKeywords(): void {
		$http = ( new FakeHttpClient() )->onJson( '/works', [
			'results' => [
				[
					'id' => 'https://openalex.org/W2741809807',
					'title' => 'A paper',
					'abstract_inverted_index' => [
						'OpenAlex' => [ 0 ], 'is' => [ 1 ], 'exact' => [ 4 ], 'and' => [ 2 ],
						'lossless' => [ 3 ], '.' => [ 5 ],
					],
					'keywords' => [ 'citation', 'identifiers' ],
				],
			],
		] );
		$provider = new OpenAlexProvider( $http );

		$records = $provider->searchByTitle( 'a paper' );

		$this->assertSame( 'OpenAlex is and lossless exact.', $records[0]->abstract );
		$this->assertSame( 'citation, identifiers', $records[0]->keywords );
	}

	public function testAbstractAndKeywordsByDoiReconstructsInvertedIndex(): void {
		$http = ( new FakeHttpClient() )->onJson( '/works/doi:', [
			'id' => 'https://openalex.org/W2741809807',
			'title' => 'A paper',
			'abstract_inverted_index' => [
				'We' => [ 0 ], 'test' => [ 1 ], 'the' => [ 2 ], 'join' => [ 3 ], '.' => [ 4 ],
			],
			'keywords' => [ 'testing' ],
		] );
		$provider = new OpenAlexProvider( $http );

		$data = $provider->abstractAndKeywordsByDoi( '10.1371/journal.pbio.2001414' );

		$this->assertSame( 'We test the join.', $data['abstract'] );
		$this->assertSame( 'testing', $data['keywords'] );
		$this->assertSame( 'openalex', $data['source'] );
	}

	public function testAbstractAndKeywordsByDoiNullOnAbsentAbstract(): void {
		$http = ( new FakeHttpClient() )->onJson( '/works/doi:', [ 'id' => 'https://openalex.org/W1', 'title' => 'No abstract' ] );
		$data = ( new OpenAlexProvider( $http ) )->abstractAndKeywordsByDoi( '10.1/x' );
		$this->assertNull( $data['abstract'] );
		$this->assertNull( $data['keywords'] );
	}
}
