<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Fetch\AuthorRecord;
use EmbeddableContent\Fetch\AuthoritySearch;
use EmbeddableContent\Fetch\PageMetadataFetcher;
use EmbeddableContent\Fetch\ProviderClient;
use EmbeddableContent\Fetch\ProviderResult;
use EmbeddableContent\Fetch\WorkRecord;
use EmbeddableContent\PropertyIdFactory;
use EmbeddableContent\Search\EntityTermSearch;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/**
 * The machine-facing authority-search orchestration: provider dispatch,
 * suggested Add* fields, and the optional local-resolution pass. The
 * providers themselves are exercised by the live E2E; here they are mocked
 * so the shaping logic is deterministic.
 *
 * @license GPL-2.0-or-later
 */
class AuthoritySearchTest extends TestCase {

	private function search(
		ProviderClient $client,
		EntityTermSearch $entitySearch,
		EmbeddableContentConfig $config,
		?callable $entityLookup = null
	): AuthoritySearch {
		return new AuthoritySearch(
			$client,
			new PageMetadataFetcher( static fn (): string => '' ),
			$entitySearch,
			$config,
			$entityLookup
		);
	}

	public function testHarvestSerializesTheSuggestedAddFields(): void {
		$client = $this->createMock( ProviderClient::class );
		$client->method( 'harvestWork' )->with( 'Q1' )->willReturn( new ProviderResult( [ new WorkRecord(
			title: 'A Book',
			doi: '10.1/x',
			issuedYear: 1843,
			authors: [ new AuthorRecord( label: 'Ada Lovelace', wikidataId: 'Q7259' ) ]
		) ] ) );
		$result = $this->search(
			$client,
			$this->createMock( EntityTermSearch::class ),
			$this->createMock( EmbeddableContentConfig::class )
		)->search( 'work', 'harvest', [ 'wikidataId' => 'Q1' ] );

		$this->assertCount( 1, $result['records'] );
		$record = $result['records'][0];
		$this->assertSame( 'A Book', $record['suggestedAddFields']['title'] );
		$this->assertSame( '1843', $record['suggestedAddFields']['year'] );
		$this->assertSame( '10.1/x', $record['suggestedAddFields']['doi'] );
		$this->assertSame( 'Ada Lovelace', $record['record']['authors'][0]['label'] );
		$this->assertArrayNotHasKey( 'local', $record );
	}

	public function testResolveLocalMatchesPublisherJournalAndAuthor(): void {
		$client = $this->createMock( ProviderClient::class );
		$client->method( 'byDoi' )->with( '10.1/x' )->willReturn( new ProviderResult( [ new WorkRecord(
			title: 'A Paper',
			containerTitle: 'Nature',
			publisher: 'MIT Press',
			doi: '10.1/x',
			authors: [ new AuthorRecord( label: 'Ada Lovelace' ) ]
		) ] ) );

		$entitySearch = $this->createMock( EntityTermSearch::class );
		$entitySearch->method( 'search' )->willReturnCallback(
			static function ( string $term ): array {
				return match ( $term ) {
					'MIT Press' => [ [ 'id' => 'Q100', 'label' => 'MIT Press' ] ],
					'Nature' => [ [ 'id' => 'Q200', 'label' => 'Nature' ] ],
					'Ada Lovelace' => [ [ 'id' => 'Q300', 'label' => 'Ada Lovelace' ] ],
					default => [],
				};
			}
		);

		$config = $this->createMock( EmbeddableContentConfig::class );
		$config->method( 'publisherClass' )->willReturn( 'Q9' );
		$config->method( 'agentClasses' )->willReturn( [ 'person' => 'Q5' ] );
		$config->method( 'scholarlyJournalClass' )->willReturn( 'Q1731' );
		$config->method( 'sourceClasses' )->willReturn( [ 'book' => 'Q9' ] );
		$config->method( 'externalIdPropertyIds' )->willReturn( [] );

		$record = $this->search( $client, $entitySearch, $config )
			->search( 'work', 'lookup', [ 'doi' => '10.1/x', 'resolveLocal' => true ] )['records'][0];

		$this->assertSame( 'Q100', $record['local']['publisher']['qid'] );
		$this->assertSame( 'Q200', $record['local']['journal']['qid'] );
		$this->assertSame( 'Q300', $record['local']['authors'][0]['qid'] );
		$this->assertSame( 'Q100', $record['suggestedAddFields']['publisher'] );
		$this->assertSame( 'Q200', $record['suggestedAddFields']['journal'] );
		$this->assertSame( 'Q300', $record['suggestedAddFields']['authors'] );
	}

	public function testResolveLocalMatchesAuthorByAuthorityId(): void {
		$client = $this->createMock( ProviderClient::class );
		$client->method( 'harvestWork' )->with( 'Q21563817' )->willReturn( new ProviderResult( [ new WorkRecord(
			title: 'EPR',
			authors: [ new AuthorRecord( label: 'Albert Einstein', wikidataId: 'Q937' ) ]
		) ] ) );

		$entitySearch = $this->createMock( EntityTermSearch::class );
		$entitySearch->method( 'search' )->willReturnCallback(
			static function ( string $term ): array {
				return $term === 'Albert Einstein'
					? [ [ 'id' => 'Q2616', 'label' => 'Albert Einstein' ] ]
					: [];
			}
		);

		$config = $this->createMock( EmbeddableContentConfig::class );
		$config->method( 'agentClasses' )->willReturn( [ 'person' => 'Q5' ] );
		$config->method( 'sourceClasses' )->willReturn( [ 'book' => 'Q9' ] );
		$config->method( 'externalIdPropertyIds' )->willReturn( [ 'wikidata' => 'P12' ] );

		$item = new Item( new ItemId( 'Q2616' ) );
		$item->getStatements()->addNewStatement(
			new PropertyValueSnak( PropertyIdFactory::create( 'P12' ), new StringValue( 'Q937' ) )
		);

		$record = $this->search(
			$client,
			$entitySearch,
			$config,
			static fn ( string $id ): ?Item => $id === 'Q2616' ? $item : null
		)->search( 'work', 'harvest', [ 'wikidataId' => 'Q21563817', 'resolveLocal' => true ] )['records'][0];

		$this->assertSame( 'Q2616', $record['local']['authors'][0]['qid'] );
		$this->assertSame( 'wikidata', $record['local']['authors'][0]['matchedBy'] );
		$this->assertTrue( $record['local']['authors'][0]['exact'] );
		$this->assertSame( 'Q2616', $record['suggestedAddFields']['authors'] );
	}

	public function testWorkSearchUsesTheClassScope(): void {
		$client = $this->createMock( ProviderClient::class );
		$client->expects( $this->once() )
			->method( 'searchWorks' )
			->with( 'Dune', [ 'Q571' ] )
			->willReturn( new ProviderResult( [ new WorkRecord( title: 'Dune' ) ] ) );

		$config = $this->createMock( EmbeddableContentConfig::class );
		$config->method( 'sourceClassSearchClasses' )->willReturn( [ 'book' => [ 'Q571' ] ] );

		$this->search( $client, $this->createMock( EntityTermSearch::class ), $config )
			->search( 'work', 'search', [ 'query' => 'Dune', 'class' => 'book' ] );
	}
}
