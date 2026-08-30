<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\SourceFlowService;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/**
 * Unit tests for the entity-mode AddSource pipeline (SourceFlowService).
 *
 * @license GPL-2.0-or-later
 */
class SourceFlowServiceTest extends TestCase {

	private const CONFIG = [
		'instanceOf' => 'P1',
		'classIds' => [ 'quotation' => 'Q2', 'code' => 'Q3', 'math' => 'Q4' ],
		'payloadPropertyIds' => [ 'quotation' => 'P2', 'code' => 'P3', 'math' => 'P4' ],
		'programmingLanguage' => 'P5',
		'fallbackLanguages' => [ 'en' ],
		'sourceClasses' => [
			'book' => 'Q8', 'scholarlyArticle' => 'Q9', 'website' => 'Q10',
			'song' => 'Q11', 'film' => 'Q12', 'video' => 'Q13',
			'youtubeChannel' => 'Q18', 'youtubeVideo' => 'Q19',
			'webpage' => 'Q20', 'bookExcerpt' => 'Q21',
		],
		'sourceParents' => [ 'bookExcerpt' => 'book', 'youtubeVideo' => 'youtubeChannel', 'webpage' => 'website' ],
		'sourceProperties' => [
			'partOf' => 'P45', 'duration' => 'P46', 'url' => 'P49',
			'youtubeChannelId' => 'P47', 'youtubeVideoId' => 'P48',
			'chapters' => 'P50', 'accessUrl' => 'P57',
		],
		'provenance' => [ 'attributedTo' => 'P6', 'date' => 'P8' ],
		'citationMetadata' => [
			'publisher' => 'P54', 'journal' => 'P55', 'pages' => 'P24',
			'volume' => 'P25', 'issue' => 'P26',
		],
		'externalIds' => [ 'wikidata' => 'P12', 'isbn' => 'P17', 'doi' => 'P16', 'openalex' => 'P18', 'pubmed' => 'P19' ],
		'agentClasses' => [ 'person' => 'Q6', 'organization' => 'Q7' ],
	];

	private function makeConfig(): EmbeddableContentConfig {
		return new EmbeddableContentConfig( self::CONFIG );
	}

	private function makeLookup(): EntityLookup {
		return new class implements EntityLookup {
			public function getEntity( EntityId $entityId ) {
				$id = $entityId->getSerialization();
				$item = new Item( new ItemId( $id ) );
				$add = static function ( string $prop, $value ) use ( $item ): void {
					$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( $prop ), $value ) );
				};
				switch ( $id ) {
					case 'Q42':
						$item->setLabel( 'en', 'Example Site' );
						$add( 'P1', new EntityIdValue( new ItemId( 'Q10' ) ) ); // website
						return $item;
					case 'Q6':
						$item->setLabel( 'en', 'Ada Lovelace' );
						$add( 'P1', new EntityIdValue( new ItemId( 'Q6' ) ) ); // person
						return $item;
					case 'Q8':
						$item->setLabel( 'en', 'The Hobbit' );
						$add( 'P1', new EntityIdValue( new ItemId( 'Q8' ) ) ); // book
						$add( 'P8', new TimeValue( '+1937-00-00T00:00:00Z', 0, 0, 0, 9, 'http://www.wikidata.org/entity/Q1985727' ) );
						$add( 'P6', new EntityIdValue( new ItemId( 'Q6' ) ) );
						return $item;
					default:
						return null;
				}
			}

			public function hasEntity( EntityId $entityId ) {
				return in_array( $entityId->getSerialization(), [ 'Q42', 'Q6', 'Q8' ], true );
			}
		};
	}

	private function makeService(): SourceFlowService {
		$classLabels = [
			'book' => 'Book', 'scholarlyArticle' => 'Scholarly article', 'website' => 'Website',
			'webpage' => 'Web page', 'song' => 'Song', 'film' => 'Film', 'video' => 'Video',
			'youtubeChannel' => 'YouTube channel', 'youtubeVideo' => 'YouTube video',
			'bookExcerpt' => 'Book excerpt',
		];
		$message = static function ( string $key, array $params ) use ( $classLabels ): string {
			if ( str_starts_with( $key, 'embeddablecontent-source-class-' ) ) {
				$formKey = substr( $key, strlen( 'embeddablecontent-source-class-' ) );
				return $classLabels[$formKey] ?? $key;
			}
			if ( $key === 'embeddablecontent-source-bookexcerpt-desc' ) {
				return implode( ' ', $params );
			}
			if ( str_ends_with( $key, '-desc-pages' ) || str_ends_with( $key, '-desc-volume' ) ) {
				return $params[0] ?? $key;
			}
			return $key;
		};
		return new SourceFlowService( $this->makeConfig(), $this->makeLookup(), $message );
	}

	// ------------------------------------------------------------- validation

	public function testWebpageRequiresAuthorsAndParent(): void {
		$service = $this->makeService();
		$record = [ 'title' => 'A Page', 'url' => 'https://example.org/page' ];

		$this->assertSame( SourceFlowService::ERROR_NO_AUTHOR, $service->prepare( 'webpage', $record, true ) );

		$record = [ 'title' => 'A Page', 'authors' => 'Q6', 'url' => 'https://example.org/page' ];
		$error = $service->prepare( 'webpage', $record, true );
		$this->assertIsString( $error );
		$this->assertStringContainsString( 'requires parent', $error );
	}

	public function testWebpageAcceptsAuthorsAndValidWebsiteParent(): void {
		$service = $this->makeService();
		$record = [ 'title' => 'A Page', 'authors' => 'Q6', 'url' => 'https://example.org/page', 'parent' => 'Q42' ];

		$this->assertNull( $service->prepare( 'webpage', $record, true ) );
		$this->assertSame( 'A Page (Web page)', $service->labelFor( 'webpage', $record ) );
	}

	public function testWebpageRejectsParentOfWrongClass(): void {
		$service = $this->makeService();
		$record = [ 'title' => 'A Page', 'authors' => 'Q6', 'parent' => 'Q6' ]; // Q6 is a person

		$error = $service->prepare( 'webpage', $record, true );
		$this->assertIsString( $error );
		$this->assertStringContainsString( 'is not an item of class Website', $error );
	}

	public function testRejectsFieldTheClassDoesNotExpose(): void {
		$service = $this->makeService();
		$record = [ 'title' => 'The Hobbit', 'authors' => 'Q6', 'isbn' => '9780547928227', 'journal' => 'Q8' ];

		$error = $service->prepare( 'book', $record, true );
		$this->assertIsString( $error );
		$this->assertStringContainsString( 'does not expose the field(s) journal', $error );
	}

	public function testRejectsUnknownClassAndBadDuration(): void {
		$service = $this->makeService();
		$empty = [];
		$this->assertIsString( $service->prepare( 'unicorn', $empty, true ) );

		$record = [ 'title' => 'A Song', 'authors' => 'Q6', 'duration' => 'not-a-duration' ];
		$this->assertIsString( $service->prepare( 'song', $record, true ) );
	}

	public function testBookExcerptFillsYearAndAuthorsFromParentBook(): void {
		$service = $this->makeService();
		$record = [ 'title' => 'Chapter 5', 'pages' => '100-120', 'volume' => '2', 'parent' => 'Q8' ];

		$this->assertNull( $service->prepare( 'book-excerpt', $record, true ) );
		$this->assertSame( '1937', $record['year'] );
		$this->assertSame( 'Q6', $record['authors'] );
		$this->assertNotEmpty( $record['description'] );
	}

	public function testTitleRequiredOnCreateOnly(): void {
		$service = $this->makeService();
		$record = [ 'authors' => 'Q6' ];
		$this->assertSame( SourceFlowService::ERROR_TITLE_REQUIRED, $service->prepare( 'book', $record, true ) );
		// Update with no title is fine (no-clobber).
		$this->assertNull( $service->prepare( 'book', $record, false ) );
	}

	// ------------------------------------------------------------- building

	public function testStatementSpecsForBook(): void {
		$service = $this->makeService();
		$record = [
			'title' => 'The Hobbit', 'authors' => 'Q6', 'publisher' => 'Q42',
			'pages' => '1-300', 'year' => '1937', 'isbn' => '9780547928227',
		];

		$specs = $service->statementSpecs( 'book', $record );

		$this->assertArrayHasKey( 'P6', $specs );
		$this->assertSame( [ 'Q6' ], array_map(
			static fn ( $v ) => $v->getEntityId()->getSerialization(),
			$specs['P6']
		) );
		$this->assertSame( 'Q42', $specs['P54']->getEntityId()->getSerialization() );
		$this->assertSame( '+1937-00-00T00:00:00Z', $specs['P8']->getTime() );
		$this->assertInstanceOf( StringValue::class, $specs['P24'] );
		$this->assertInstanceOf( StringValue::class, $specs['P17'] );
	}

	public function testBuildItemCarriesSuffixedLabelClassAndStatements(): void {
		$service = $this->makeService();
		$record = [ 'title' => 'The Hobbit', 'authors' => 'Q6', 'year' => '1937' ];

		$item = $service->buildItem( 'book', $record );

		$this->assertSame( 'The Hobbit (Book)', $item->getLabels()->getByLanguage( 'en' )->getText() );
		$this->assertTrue( $this->hasStatement( $item, 'P1', 'Q8' ) );
		$this->assertTrue( $this->hasStatement( $item, 'P6', 'Q6' ) );
		$this->assertTrue( $this->hasStatement( $item, 'P8', null ) );
	}

	public function testApplyUpdateIsNoClobber(): void {
		$service = $this->makeService();
		$item = new Item( new ItemId( 'Q777' ) );
		$item->setLabel( 'en', 'Old Title (Book)' );
		$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( 'P17' ), new StringValue( 'old-isbn' ) ) );
		$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( 'P24' ), new StringValue( '1-300' ) ) );

		$service->applyUpdate( 'book', $item, [ 'isbn' => '9780547928227' ] );

		$properties = [];
		foreach ( $item->getStatements() as $statement ) {
			$properties[] = $statement->getPropertyId()->getSerialization();
		}
		// ISBN replaced once, pages kept, instance-of untouched (none here).
		$this->assertSame( 1, count( array_keys( $properties, 'P17', true ) ) );
		$this->assertContains( 'P24', $properties );
		$isbn = $this->statementValue( $item, 'P17' );
		$this->assertSame( '9780547928227', $isbn );
		// Label replaced verbatim (no suffix re-added on update).
		$service->applyUpdate( 'book', $item, [ 'title' => 'New Title' ] );
		$this->assertSame( 'New Title', $item->getLabels()->getByLanguage( 'en' )->getText() );
	}

	public function testManagedPropertyIdsCoverOnlyProvidedFields(): void {
		$service = $this->makeService();
		$ids = $service->managedPropertyIds( 'book', [ 'isbn' => 'x', 'year' => '1937' ] );
		$this->assertSame( [ 'P17', 'P8' ], $ids );
	}

	// ------------------------------------------------------------- helpers

	private function hasStatement( Item $item, string $property, ?string $entityId ): bool {
		foreach ( $item->getStatements() as $statement ) {
			if ( $statement->getPropertyId()->getSerialization() !== $property ) {
				continue;
			}
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof EntityIdValue && $entityId !== null
				&& $value->getEntityId()->getSerialization() === $entityId
			) {
				return true;
			}
			if ( $entityId === null ) {
				return true;
			}
		}
		return false;
	}

	private function statementValue( Item $item, string $property ): ?string {
		foreach ( $item->getStatements() as $statement ) {
			if ( $statement->getPropertyId()->getSerialization() === $property ) {
				$value = $statement->getMainSnak()->getDataValue();
				return $value instanceof StringValue ? $value->getValue() : null;
			}
		}
		return null;
	}
}
