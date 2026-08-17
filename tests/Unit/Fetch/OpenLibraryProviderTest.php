<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\OpenLibraryProvider;
use EmbeddableContent\Fetch\WorkRecord;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class OpenLibraryProviderTest extends TestCase {

	public function testSearchByTitle(): void {
		$http = ( new FakeHttpClient() )->onJson( '/search.json', [
			'docs' => [
				[
					'title' => 'The Old Man and the Sea',
					'publisher' => [ "Charles Scribner's Sons" ],
					'isbn_13' => [ '9780684801223' ],
					'first_publish_year' => 1952,
					'key' => '/books/OL1234567W',
				],
			],
		] );
		$provider = new OpenLibraryProvider( $http );

		$records = $provider->searchByTitle( 'old man sea' );

		$this->assertCount( 1, $records );
		$this->assertInstanceOf( WorkRecord::class, $records[0] );
		$this->assertSame( 'The Old Man and the Sea', $records[0]->title );
		$this->assertSame( "Charles Scribner's Sons", $records[0]->publisher );
		$this->assertSame( '9780684801223', $records[0]->isbn );
		$this->assertSame( 1952, $records[0]->issuedYear );
		$this->assertSame( '/books/OL1234567W', $records[0]->providerId );
	}

	public function testByIsbn(): void {
		$http = ( new FakeHttpClient() )->onJson( '/isbn/9780684801223.json', [
			'title' => 'The Old Man and the Sea',
			'publishers' => [ "Charles Scribner's Sons" ],
			'publish_date' => '1952-09-01',
			'isbn_13' => [ '9780684801223' ],
			'key' => '/books/OL1234567W',
		] );
		$provider = new OpenLibraryProvider( $http );

		$record = $provider->byIsbn( '9780684801223' );

		$this->assertNotNull( $record );
		$this->assertSame( 'The Old Man and the Sea', $record->title );
		$this->assertSame( 1952, $record->issuedYear );
	}

	public function testByDoiReturnsNull(): void {
		$provider = new OpenLibraryProvider( new FakeHttpClient() );
		$this->assertNull( $provider->byDoi( '10.1000/xyz' ) );
	}
}
