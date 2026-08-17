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
		$this->assertSame( '28379999', $records[0]->pubmedId );
		$this->assertSame( 2017, $records[0]->issuedYear );
	}

	public function testByIsbnReturnsNull(): void {
		$provider = new OpenAlexProvider( new FakeHttpClient() );
		$this->assertNull( $provider->byIsbn( '9780684801223' ) );
	}
}
