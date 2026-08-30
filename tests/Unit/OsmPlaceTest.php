<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Spec\OsmPlace;
use PHPUnit\Framework\TestCase;

/**
 * OpenStreetMap place-id validation for the person place fields.
 *
 * @covers \EmbeddableContent\Spec\OsmPlace
 * @license GPL-2.0-or-later
 */
class OsmPlaceTest extends TestCase {

	public function testValidIds(): void {
		$this->assertTrue( OsmPlace::isValidId( 'node/261512419' ) );
		$this->assertTrue( OsmPlace::isValidId( 'way/123456789' ) );
		$this->assertTrue( OsmPlace::isValidId( 'relation/295355' ) );
		$this->assertTrue( OsmPlace::isValidId( 'node/1' ) );
		$this->assertTrue( OsmPlace::isValidId( '  node/123  ' ) );
	}

	public function testInvalidIds(): void {
		// A place NAME (an unpicked harvested label) must never pass.
		$this->assertFalse( OsmPlace::isValidId( 'Cambridge' ) );
		$this->assertFalse( OsmPlace::isValidId( 'New York City' ) );
		// Wrong shapes.
		$this->assertFalse( OsmPlace::isValidId( '' ) );
		$this->assertFalse( OsmPlace::isValidId( 'node/' ) );
		$this->assertFalse( OsmPlace::isValidId( 'node/0' ) );
		$this->assertFalse( OsmPlace::isValidId( 'node' ) );
		$this->assertFalse( OsmPlace::isValidId( '/123' ) );
		$this->assertFalse( OsmPlace::isValidId( '123' ) );
		$this->assertFalse( OsmPlace::isValidId( 'Q42' ) );
		$this->assertFalse( OsmPlace::isValidId( 'node/123/extra' ) );
		$this->assertFalse( OsmPlace::isValidId( 'node/12a' ) );
		// Unknown OSM element types.
		$this->assertFalse( OsmPlace::isValidId( 'area/123' ) );
	}
}
