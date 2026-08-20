<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use DataValues\StringValue;
use DataValues\TimeValue;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;
use WikibaseCitation\CitationMapLookup;
use WikibaseCitation\CslTypeMapper;
use WikibaseCitation\StatementToCslConverter;

/**
 * Unit tests for the statement -> CSL-JSON conversion using the real
 * wikibase/data-model classes and a fake map lookup / entity lookup.
 *
 * @license GPL-2.0-or-later
 */
class StatementToCslConverterTest extends TestCase {

	private function makeMapLookup(): CitationMapLookup {
		return new class implements CitationMapLookup {
			public function getPropertyForField( string $field ): ?string {
				return [
					'author' => 'P6',
					'container-title' => 'P8',
					'issued' => 'P9',
					'URL' => 'P7',
				][$field] ?? null;
			}

			public function getSourcePropertyForField( string $field ): ?string {
				return [
					'container-title' => 'P21',
					'publisher' => 'P22',
					'volume' => 'P23',
					'page' => 'P23',
					'DOI' => 'P24',
				][$field] ?? null;
			}

			public function getTypeForClass( string $classId ): ?string {
				return $classId === 'Q5' ? 'article' : null;
			}

			public function getTypeForSourceClass( string $classId ): ?string {
				return $classId === 'Q20' ? 'book' : null;
			}
		};
	}

	private function makeEntityLookup(): EntityLookup {
		return new class implements EntityLookup {
			public function getEntity( EntityId $entityId ) {
				if ( $entityId->getSerialization() === 'Q10' ) {
					$item = new Item( new ItemId( 'Q10' ) );
					$item->setLabel( 'en', 'Ada Lovelace' );
					return $item;
				}
				if ( $entityId->getSerialization() === 'Q20' ) {
					$item = new Item( new ItemId( 'Q20' ) );
					$item->setLabel( 'en', 'Notes by the Translator' );
					$item->setLabel( 'fr', 'Notes de la traductrice' );
					$item->getStatements()->addNewStatement(
						new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q20' ) ) )
					);
					$item->getStatements()->addNewStatement(
						new PropertyValueSnak( new PropertyId( 'P21' ), new StringValue( 'Scientific Memoirs' ) )
					);
					$item->getStatements()->addNewStatement(
						new PropertyValueSnak( new PropertyId( 'P22' ), new StringValue( 'R. & J. E. Taylor' ) )
					);
					$item->getStatements()->addNewStatement(
						new PropertyValueSnak( new PropertyId( 'P24' ), new StringValue( '10.1000/notes' ) )
					);
					return $item;
				}
				if ( $entityId->getSerialization() === 'Q30' ) {
					// A book source WITHOUT `published in`: container-title
					// must fall back to the label.
					$item = new Item( new ItemId( 'Q30' ) );
					$item->setLabel( 'en', 'The Old Man and the Sea' );
					$item->getStatements()->addNewStatement(
						new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q20' ) ) )
					);
					return $item;
				}
				return null;
			}

			public function hasEntity( EntityId $entityId ) {
				return in_array( $entityId->getSerialization(), [ 'Q10', 'Q20', 'Q30' ], true );
			}
		};
	}

	private function makeItem(): Item {
		$item = new Item();
		$item->setLabel( 'en', 'The Analytical Engine has no pretensions whatever to originate anything' );

		$add = static function ( string $prop, $value ) use ( $item ): void {
			$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( $prop ), $value ) );
		};
		$add( 'P31', new EntityIdValue( new ItemId( 'Q5' ) ) );   // instance of quotation class
		$add( 'P6', new EntityIdValue( new ItemId( 'Q10' ) ) );   // attributed to Ada
		$add( 'P7', new StringValue( 'https://example.org/notes' ) );
		$add( 'P9', new TimeValue( '+1843-01-01T00:00:00Z', 0, 0, 0, 9, 'http://www.wikidata.org/entity/Q1985727' ) );
		return $item;
	}

	public function testConversion(): void {
		$converter = new StatementToCslConverter(
			$this->makeEntityLookup(),
			$this->makeMapLookup(),
			new CslTypeMapper( $this->makeMapLookup() ),
			'P31'
		);

		$csl = $converter->toCslJson( $this->makeItem() );

		$this->assertSame( 'article', $csl['type'] );
		$this->assertSame( 'The Analytical Engine has no pretensions whatever to originate anything', $csl['title'] );
		$this->assertSame( [ [ 'given' => 'Ada', 'family' => 'Lovelace' ] ], $csl['author'] );
		$this->assertSame( [ 'date-parts' => [ [ 1843 ] ] ], $csl['issued'] );
		$this->assertSame( 'https://example.org/notes', $csl['URL'] );
	}

	public function testMissingFieldsAreOmitted(): void {
		$converter = new StatementToCslConverter(
			$this->makeEntityLookup(),
			$this->makeMapLookup(),
			new CslTypeMapper( $this->makeMapLookup() ),
			'P31'
		);
		$item = new Item();
		$item->setLabel( 'en', 'Bare item' );
		$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q5' ) ) ) );

		$csl = $converter->toCslJson( $item );
		$this->assertArrayNotHasKey( 'author', $csl );
		$this->assertArrayNotHasKey( 'URL', $csl );
		$this->assertSame( 'article', $csl['type'] );
	}

	public function testUnknownClassFallsBackToDefaultType(): void {
		$converter = new StatementToCslConverter(
			$this->makeEntityLookup(),
			$this->makeMapLookup(),
			new CslTypeMapper( $this->makeMapLookup() ),
			'P31'
		);
		$item = new Item();
		$item->setLabel( 'en', 'Unknown class item' );
		$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q999' ) ) ) );

		$this->assertSame( 'article', $converter->toCslJson( $item )['type'] );
	}

	public function testDeprecatedClaimsAreSkipped(): void {
		$converter = new StatementToCslConverter(
			$this->makeEntityLookup(),
			$this->makeMapLookup(),
			new CslTypeMapper( $this->makeMapLookup() ),
			'P31'
		);
		$item = new Item();
		$item->setLabel( 'en', 'Rank test' );
		$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q5' ) ) ) );

		$deprecated = new Statement( new PropertyValueSnak( new PropertyId( 'P6' ), new EntityIdValue( new ItemId( 'Q10' ) ) ) );
		$deprecated->setRank( Statement::RANK_DEPRECATED );
		$item->getStatements()->addStatement( $deprecated );

		$csl = $converter->toCslJson( $item );
		$this->assertArrayNotHasKey( 'author', $csl );
	}

	public function testTypeFollowsSourceClassAndSourceFieldsAreResolved(): void {
		$converter = new StatementToCslConverter(
			$this->makeEntityLookup(),
			$this->makeMapLookup(),
			new CslTypeMapper( $this->makeMapLookup() ),
			'P31'
		);
		$item = new Item();
		$item->setLabel( 'en', 'A quotation from a book' );
		$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q5' ) ) ) );
		// `source` statement (P8) pointing at the book item Q20.
		$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( 'P8' ), new EntityIdValue( new ItemId( 'Q20' ) ) ) );

		$csl = $converter->toCslJson( $item );

		// Issue #7: CSL type follows the SOURCE class (Q20 -> book).
		$this->assertSame( 'book', $csl['type'] );
		$this->assertSame( 'Scientific Memoirs', $csl['container-title'] );
		$this->assertSame( 'R. & J. E. Taylor', $csl['publisher'] );
		$this->assertSame( '10.1000/notes', $csl['DOI'] );
		// Source-level volume is unmapped in the fake -> omitted, not fatal.
		$this->assertArrayNotHasKey( 'volume', $csl );
	}

	public function testContainerTitleFallsBackToSourceLabelForBooks(): void {
		$converter = new StatementToCslConverter(
			$this->makeEntityLookup(),
			$this->makeMapLookup(),
			new CslTypeMapper( $this->makeMapLookup() ),
			'P31'
		);
		$item = new Item();
		$item->setLabel( 'en', 'Another quotation' );
		$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q5' ) ) ) );
		// Source Q30 is a book WITHOUT `published in` (P21).
		$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( 'P8' ), new EntityIdValue( new ItemId( 'Q30' ) ) ) );

		$csl = $converter->toCslJson( $item );

		// Label fallback: the source item's label IS the container title.
		$this->assertSame( 'The Old Man and the Sea', $csl['container-title'] );
		$this->assertSame( 'book', $csl['type'] );
	}

	public function testSourceClassItemCitedDirectlyIsItsOwnSource(): void {
		// Regression (issue #24): a source-class item cited directly must
		// read the source-level fields from ITSELF — publisher / DOI /
		// container must be present, not omitted.
		$converter = new StatementToCslConverter(
			$this->makeEntityLookup(),
			$this->makeMapLookup(),
			new CslTypeMapper( $this->makeMapLookup() ),
			'P31',
			[ 'Q20' ]   // Q20 is the book source class
		);
		$book = $this->makeEntityLookup()->getEntity( new ItemId( 'Q20' ) );
		$this->assertInstanceOf( Item::class, $book );

		$csl = $converter->toCslJson( $book );

		$this->assertSame( 'book', $csl['type'] );
		$this->assertSame( 'Scientific Memoirs', $csl['container-title'] );
		$this->assertSame( 'R. & J. E. Taylor', $csl['publisher'] );
		$this->assertSame( '10.1000/notes', $csl['DOI'] );
	}

	public function testSourceItemWithoutSourceClassesKeepsOldBehaviour(): void {
		// Without the source-class config the converter must NOT self-cite:
		// the source-level fields stay omitted (pre-#24 behaviour).
		$converter = new StatementToCslConverter(
			$this->makeEntityLookup(),
			$this->makeMapLookup(),
			new CslTypeMapper( $this->makeMapLookup() ),
			'P31'
		);
		$book = $this->makeEntityLookup()->getEntity( new ItemId( 'Q20' ) );

		$csl = $converter->toCslJson( $book );

		$this->assertArrayNotHasKey( 'publisher', $csl );
		$this->assertArrayNotHasKey( 'DOI', $csl );
	}

	public function testIsSourceClassMatchesConfiguredClasses(): void {
		$converter = new StatementToCslConverter(
			$this->makeEntityLookup(),
			$this->makeMapLookup(),
			new CslTypeMapper( $this->makeMapLookup() ),
			'P31',
			[ 'Q20' ]
		);
		$book = $this->makeEntityLookup()->getEntity( new ItemId( 'Q20' ) );
		$quotation = $this->makeItem();

		$this->assertTrue( $converter->isSourceClass( $book ) );
		$this->assertFalse( $converter->isSourceClass( $quotation ) );
	}

	public function testPageQualifierOnSourceStatementBecomesLocator(): void {
		// v2 (issue #25): a page(s) QUALIFIER on the content item's `source`
		// statement is the quote-level locator → CSL 'page' (overrides the
		// work-level page range read from the source item).
		$converter = new StatementToCslConverter(
			$this->makeEntityLookup(),
			$this->makeMapLookup(),
			new CslTypeMapper( $this->makeMapLookup() ),
			'P31',
			[ 'Q20' ]
		);
		$item = new Item();
		$item->setLabel( 'en', 'A quotation with a page qualifier' );
		$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q5' ) ) ) );
		// Source statement with a page(s) qualifier (P23 → '42–44').
		$statement = new Statement( new PropertyValueSnak( new PropertyId( 'P8' ), new EntityIdValue( new ItemId( 'Q20' ) ) ) );
		$statement->getQualifiers()->addSnak( new PropertyValueSnak( new PropertyId( 'P23' ), new StringValue( '42–44' ) ) );
		$item->getStatements()->addStatement( $statement );

		$csl = $converter->toCslJson( $item );

		$this->assertSame( '42–44', $csl['page'] );
	}

	public function testMissingPageQualifierLeavesNoLocator(): void {
		$converter = new StatementToCslConverter(
			$this->makeEntityLookup(),
			$this->makeMapLookup(),
			new CslTypeMapper( $this->makeMapLookup() ),
			'P31',
			[ 'Q20' ]
		);
		$item = new Item();
		$item->setLabel( 'en', 'A quotation without a page qualifier' );
		$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q5' ) ) ) );
		$item->getStatements()->addNewStatement( new PropertyValueSnak( new PropertyId( 'P8' ), new EntityIdValue( new ItemId( 'Q20' ) ) ) );

		$csl = $converter->toCslJson( $item );

		$this->assertArrayNotHasKey( 'page', $csl );
	}
}
