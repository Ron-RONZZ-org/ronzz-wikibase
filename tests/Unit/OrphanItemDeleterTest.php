<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use DataValues\StringValue;
use EmbeddableContent\Flow\OrphanItemDeleter;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/**
 * The page-deletion orphan rule: only a label + single sitelink shell is
 * deleted; any authored content keeps the item.
 *
 * @license GPL-2.0-or-later
 */
class OrphanItemDeleterTest extends TestCase {

	private function shell(): Item {
		$item = new Item( new ItemId( 'Q1' ) );
		$item->setLabel( 'en', 'Club of Rome' );
		$item->getSiteLinkList()->setNewSiteLink( 'wikibase', 'Club of Rome' );
		return $item;
	}

	public function testLabelOnlyShellIsDeleted(): void {
		$this->assertTrue( OrphanItemDeleter::shouldDelete( $this->shell(), 'Club of Rome' ) );
	}

	public function testUnderscoreSpellingIsEquivalent(): void {
		$this->assertTrue( OrphanItemDeleter::shouldDelete( $this->shell(), 'Club_of_Rome' ) );
	}

	public function testStatementKeepsTheItem(): void {
		$item = $this->shell();
		$item->getStatements()->addNewStatement(
			new PropertyValueSnak( new PropertyId( 'P1' ), new StringValue( 'x' ) )
		);
		$this->assertFalse( OrphanItemDeleter::shouldDelete( $item, 'Club of Rome' ) );
	}

	public function testDescriptionKeepsTheItem(): void {
		$item = $this->shell();
		$item->setDescription( 'en', 'A think tank' );
		$this->assertFalse( OrphanItemDeleter::shouldDelete( $item, 'Club of Rome' ) );
	}

	public function testAliasKeepsTheItem(): void {
		$item = $this->shell();
		$item->setAliases( 'en', [ 'COR' ] );
		$this->assertFalse( OrphanItemDeleter::shouldDelete( $item, 'Club of Rome' ) );
	}

	public function testOtherSitelinkKeepsTheItem(): void {
		$item = $this->shell();
		$item->getSiteLinkList()->setNewSiteLink( 'other', 'Elsewhere' );
		$this->assertFalse( OrphanItemDeleter::shouldDelete( $item, 'Club of Rome' ) );
	}

	public function testDifferentPageKeepsTheItem(): void {
		$this->assertFalse( OrphanItemDeleter::shouldDelete( $this->shell(), 'Another page' ) );
	}

	public function testNoSitelinkKeepsTheItem(): void {
		$item = new Item( new ItemId( 'Q1' ) );
		$item->setLabel( 'en', 'X' );
		$this->assertFalse( OrphanItemDeleter::shouldDelete( $item, 'X' ) );
	}

}
