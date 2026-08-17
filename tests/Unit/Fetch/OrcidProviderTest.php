<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\OrcidProvider;
use EmbeddableContent\Fetch\PersonRecord;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class OrcidProviderTest extends TestCase {

	public function testByOrcid(): void {
		$http = ( new FakeHttpClient() )->onJson( '/record', [
			'orcid-identifier' => [ 'path' => '0000-0001-6187-6610' ],
			'person' => [
				'name' => [
					'given-names' => [ 'value' => 'Jason' ],
					'family-name' => [ 'value' => 'Priem' ],
				],
			],
		] );
		$provider = new OrcidProvider( $http );

		$record = $provider->byOrcid( '0000-0001-6187-6610' );

		$this->assertInstanceOf( PersonRecord::class, $record );
		$this->assertSame( 'Jason Priem', $record->label );
		$this->assertSame( 'Jason', $record->givenName );
		$this->assertSame( 'Priem', $record->familyName );
		$this->assertSame( '0000-0001-6187-6610', $record->orcid );
		$this->assertSame( 'orcid', $record->provider );
	}

	public function testExpandedSearch(): void {
		$http = ( new FakeHttpClient() )->onJson( 'expanded-search', [
			'expanded-result' => [
				[ 'orcid-id' => '0000-0001-6187-6610', 'given-names' => 'Jason', 'family-names' => 'Priem' ],
				[ 'orcid-id' => '0000-0002-1298-3089', 'given-names' => 'Heather', 'family-names' => 'Piwowar' ],
			],
		] );
		$provider = new OrcidProvider( $http );

		$records = $provider->searchByName( 'jason priem' );

		$this->assertCount( 2, $records );
		$this->assertSame( 'Jason Priem', $records[0]->label );
		$this->assertSame( '0000-0001-6187-6610', $records[0]->orcid );
		// The Accept header must be present for the ORCID public API.
		$this->assertSame( 'application/json', $http->calls[0]['headers']['Accept'] ?? null );
	}

	public function testByOrcidReturnsNullWithoutPersonName(): void {
		$http = ( new FakeHttpClient() )->onJson( '/record', [ 'orcid-identifier' => [ 'path' => '0000-0000-0000-0000' ] ] );
		$provider = new OrcidProvider( $http );

		$this->assertNull( $provider->byOrcid( '0000-0000-0000-0000' ) );
	}
}
