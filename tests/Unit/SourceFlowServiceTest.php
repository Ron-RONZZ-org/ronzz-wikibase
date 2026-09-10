<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\SourceFieldMap;
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
			'court' => 'P60', 'territorialJurisdictionOsm' => 'P61',
			'territorialJurisdictionLabel' => 'P62', 'caseNumber' => 'P63',
			'patentNumber' => 'P64', 'reportNumber' => 'P65', 'legislationNumber' => 'P66',
			'international' => 'P67',
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
			'text' => 'Text', 'legalCase' => 'Legal case', 'treaty' => 'Treaty',
			'legislation' => 'Legislation', 'bill' => 'Bill',
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

	public function testWebpageRequiresParentButNotAuthors(): void {
		$service = $this->makeService();
		// Title + parent only: authors are OPTIONAL on create (a text of
		// unknown authorship is legitimate). The parent error fires.
		$record = [ 'title' => 'A Page', 'url' => 'https://example.org/page' ];
		$error = $service->prepare( 'webpage', $record, true );
		$this->assertIsString( $error );
		$this->assertStringContainsString( 'requires parent', $error );
	}

	public function testAuthorsAreOptionalOnCreate(): void {
		$service = $this->makeService();
		$record = [ 'title' => 'The Hobbit' ];
		$this->assertNull( $service->prepare( 'book', $record, true ) );
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

	public function testStatementSpecsForLegalCase(): void {
		$service = $this->makeService();
		$record = [
			'title' => 'Roe v. Wade',
			'court' => 'Q42',
			'territorialJurisdiction' => 'relation/12345',
			'territorialJurisdictionLabel' => 'United States',
			'caseNumber' => '410 U.S. 113',
			'year' => '1973',
		];

		$specs = $service->statementSpecs( 'legal-case', $record );

		$this->assertSame( 'Q42', $specs['P60']->getEntityId()->getSerialization() );
		// Multi-value: one external-id statement per jurisdiction.
		$this->assertSame(
			[ 'relation/12345' ],
			array_map( static fn ( $v ) => $v->getValue(), $specs['P61'] )
		);
		// The parallel label map is JSON (id => display name); the legacy
		// plain label attaches to the single id.
		$this->assertSame(
			[ 'relation/12345' => 'United States' ],
			json_decode( $specs['P62']->getValue(), true )
		);
		$this->assertSame( '410 U.S. 113', $specs['P63']->getValue() );
		$this->assertSame( '+1973-00-00T00:00:00Z', $specs['P8']->getTime() );
	}

	public function testMultiJurisdictionWritesOneStatementPerIdAndPrunesLabels(): void {
		$service = $this->makeService();
		$record = [
			'title' => 'A Treaty',
			'territorialJurisdiction' => 'relation/123, relation/456',
			'territorialJurisdictionLabel' => '{"relation/123":"France","relation/456":"Germany","relation/999":"Stale"}',
		];

		$specs = $service->statementSpecs( 'treaty', $record );

		$this->assertSame(
			[ 'relation/123', 'relation/456' ],
			array_map( static fn ( $v ) => $v->getValue(), $specs['P61'] )
		);
		$this->assertSame(
			[ 'relation/123' => 'France', 'relation/456' => 'Germany' ],
			json_decode( $specs['P62']->getValue(), true )
		);
	}

	public function testInternationalMarkerReplacesJurisdiction(): void {
		$service = $this->makeService();
		$record = [
			'title' => 'A Treaty',
			'international' => '1',
			'territorialJurisdiction' => 'relation/123',
			'territorialJurisdictionLabel' => '{"relation/123":"France"}',
		];

		$specs = $service->statementSpecs( 'treaty', $record );

		$this->assertSame( 'yes', $specs['P67']->getValue() );
		// The jurisdiction properties are present but EMPTY: an update
		// removes their stale statements; a create adds nothing.
		$this->assertSame( [], $specs['P61'] );
		$this->assertSame( [], $specs['P62'] );
	}

	public function testUncheckedInternationalClearsMarker(): void {
		$service = $this->makeService();
		$record = [ 'title' => 'A Treaty', 'international' => '' ];

		$specs = $service->statementSpecs( 'treaty', $record );

		// An unchecked submit emits an EMPTY spec: an update removes the
		// stale marker, a create adds nothing.
		$this->assertArrayHasKey( 'P67', $specs );
		$this->assertSame( [], $specs['P67'] );
	}

	public function testTextCatchAllClassBuilds(): void {
		$service = $this->makeService();
		$record = [ 'title' => 'Codex Sinaiticus' ];

		$this->assertNull( $service->prepare( 'text', $record, true ) );
		$this->assertSame( [ 'title' ], SourceFieldMap::requiredOnCreate( 'text' ) );
		$this->assertSame( 'Codex Sinaiticus (Text)', $service->labelFor( 'text', $record ) );
	}

	public function testLegalCaseDoesNotRequireAuthors(): void {
		$service = $this->makeService();
		$record = [ 'title' => 'Roe v. Wade' ];
		$this->assertNull( $service->prepare( 'legal-case', $record, true ) );
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
