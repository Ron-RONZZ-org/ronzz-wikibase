<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\EntityClassFilter;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/**
 * Pure unit tests for the shared `instance of` class filter
 * (EntityClassFilter) — parseItemIds + hasAnyClass/hasClass.
 *
 * @covers \EmbeddableContent\EntityClassFilter
 * @license GPL-2.0-or-later
 */
final class EntityClassFilterTest extends TestCase {

	private function itemWithClass( string $itemId, string $classId ): Item {
		$item = new Item( new ItemId( $itemId ) );
		$item->getStatements()->addNewStatement(
			new PropertyValueSnak(
				new PropertyId( 'P1' ),
				new EntityIdValue( new ItemId( $classId ) )
			)
		);
		return $item;
	}

	public function testParseItemIdsKeepsValidDedupesAndUppercases(): void {
		$this->assertSame(
			[ 'Q302', 'Q5' ],
			EntityClassFilter::parseItemIds( 'Q302| q5 |garbage|Q0|Q302|' )
		);
	}

	public function testParseItemIdsEmpty(): void {
		$this->assertSame( [], EntityClassFilter::parseItemIds( '' ) );
	}

	public function testHasAnyClassMatchesOneOfTheScopes(): void {
		$item = $this->itemWithClass( 'Q42', 'Q302' );
		$this->assertTrue( EntityClassFilter::hasAnyClass( $item, [ 'Q5', 'Q302' ], 'P1' ) );
		$this->assertFalse( EntityClassFilter::hasAnyClass( $item, [ 'Q5', 'Q6' ], 'P1' ) );
	}

	public function testHasClassSingle(): void {
		$item = $this->itemWithClass( 'Q42', 'Q302' );
		$this->assertTrue( EntityClassFilter::hasClass( $item, 'Q302', 'P1' ) );
		$this->assertFalse( EntityClassFilter::hasClass( $item, 'Q303', 'P1' ) );
	}

	public function testEmptyScopeIsNeverAMatch(): void {
		$item = $this->itemWithClass( 'Q42', 'Q302' );
		$this->assertFalse( EntityClassFilter::hasAnyClass( $item, [], 'P1' ) );
	}

	public function testUsesTheConfiguredInstanceOfProperty(): void {
		// The statement uses P1; a filter configured for P31 must not match.
		$item = $this->itemWithClass( 'Q42', 'Q302' );
		$this->assertFalse( EntityClassFilter::hasAnyClass( $item, [ 'Q302' ], 'P31' ) );
	}
}
