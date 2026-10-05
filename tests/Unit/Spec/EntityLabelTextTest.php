<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Tests\Unit\Spec;

use EmbeddableContent\Spec\EntityLabelText;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;

/**
 * @covers \EmbeddableContent\Spec\EntityLabelText
 * @license GPL-2.0-or-later
 */
class EntityLabelTextTest extends TestCase {

	public function testPrefersTheRequestedLanguage(): void {
		$item = new Item( new ItemId( 'Q1' ) );
		$item->setLabel( 'en', 'The Hobbit' );
		$item->setLabel( 'fr', 'Le Hobbit' );

		$this->assertSame( 'Le Hobbit', EntityLabelText::of( $item, 'fr' ) );
		$this->assertSame( 'The Hobbit', EntityLabelText::of( $item, 'en' ) );
	}

	public function testFallsBackToEnglishThenAny(): void {
		$frOnly = new Item( new ItemId( 'Q2' ) );
		$frOnly->setLabel( 'fr', 'Le Hobbit' );

		// An en-preferred read of an fr-only item must not throw; it returns
		// the first available label.
		$this->assertSame( 'Le Hobbit', EntityLabelText::of( $frOnly, 'en' ) );

		$eoOnly = new Item( new ItemId( 'Q3' ) );
		$eoOnly->setLabel( 'eo', 'La Hobito' );
		$this->assertSame( 'La Hobito', EntityLabelText::of( $eoOnly, 'fr' ) );
	}

	public function testReturnsNullWhenTheItemHasNoLabel(): void {
		$item = new Item( new ItemId( 'Q4' ) );
		$this->assertNull( EntityLabelText::of( $item, 'en' ) );
	}
}
