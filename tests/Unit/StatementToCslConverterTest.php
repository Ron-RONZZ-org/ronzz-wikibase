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

			public function getTypeForClass( string $classId ): ?string {
				return $classId === 'Q5' ? 'article' : null;
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
				return null;
			}

			public function hasEntity( EntityId $entityId ) {
				return $entityId->getSerialization() === 'Q10';
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
}
