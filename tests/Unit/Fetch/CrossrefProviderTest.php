<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\CrossrefProvider;
use EmbeddableContent\Fetch\WorkRecord;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class CrossrefProviderTest extends TestCase {

	public function testByDoiMapsMetadata(): void {
		$http = ( new FakeHttpClient() )->onJson( '/works/10.1371%2Fjournal.pbio.2001414', [
			'message' => [
				'title' => [ 'Identifiers for the 21st century' ],
				'author' => [ [ 'given' => 'Julie A.', 'family' => 'McMurry' ] ],
				'container-title' => [ 'PLOS Biology' ],
				'publisher' => 'Public Library of Science',
				'volume' => '15',
				'issue' => '4',
				'page' => 'e2001414',
				'DOI' => '10.1371/journal.pbio.2001414',
				'ISBN' => [],
				'issued' => [ 'date-parts' => [ [ 2017, 4, 6 ] ] ],
			],
		] );
		$provider = new CrossrefProvider( $http );

		$record = $provider->byDoi( '10.1371/journal.pbio.2001414' );

		$this->assertInstanceOf( WorkRecord::class, $record );
		$this->assertSame( 'Identifiers for the 21st century', $record->title );
		$this->assertSame( 'PLOS Biology', $record->containerTitle );
		$this->assertSame( 'Public Library of Science', $record->publisher );
		$this->assertSame( '15', $record->volume );
		$this->assertSame( '4', $record->issue );
		$this->assertSame( 'e2001414', $record->pages );
		$this->assertSame( '10.1371/journal.pbio.2001414', $record->doi );
		$this->assertSame( 2017, $record->issuedYear );
		$this->assertSame( 'crossref', $record->provider );
	}

	public function testSearchByTitle(): void {
		$http = ( new FakeHttpClient() )->onJson( 'query.title', [
			'message' => [
				'items' => [
					[ 'title' => [ 'The Old Man and the Sea' ], 'DOI' => '10.1000/xyz', 'issued' => [ 'date-parts' => [ [ 1952 ] ] ] ],
				],
			],
		] );
		$provider = new CrossrefProvider( $http );

		$records = $provider->searchByTitle( 'old man sea' );

		$this->assertCount( 1, $records );
		$this->assertSame( 'The Old Man and the Sea', $records[0]->title );
		$this->assertSame( 1952, $records[0]->issuedYear );
	}
}
