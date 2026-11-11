<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\PropertyIdFactory;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Entity\PropertyId;

/**
 * The one PropertyId factory. On the deployed Wikibase bundle PropertyId is
 * an interface and NumericPropertyId is the concrete class; the unit-test
 * vendor (9.6.1) still has PropertyId concrete. The factory hides that split
 * from every caller.
 *
 * @license GPL-2.0-or-later
 */
class PropertyIdFactoryTest extends TestCase {

	public function testCreateReturnsAPropertyIdWithTheGivenSerialization(): void {
		$id = PropertyIdFactory::create( 'P42' );

		$this->assertInstanceOf( PropertyId::class, $id );
		$this->assertSame( 'P42', $id->getSerialization() );
	}

	public function testCreateUsesTheConcreteClassWhenTheRuntimeProvidesIt(): void {
		if ( !class_exists( NumericPropertyId::class ) ) {
			$this->markTestSkipped( 'the unit-test vendor has PropertyId concrete' );
		}

		$this->assertInstanceOf( NumericPropertyId::class, PropertyIdFactory::create( 'P42' ) );
	}

}
