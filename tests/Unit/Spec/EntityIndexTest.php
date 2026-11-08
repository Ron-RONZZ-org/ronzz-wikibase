<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Tests\Unit\Spec;

use EmbeddableContent\Spec\EntityIndexPaging;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Spec\EntityIndexPaging
 * @license GPL-2.0-or-later
 */
class EntityIndexTest extends TestCase {

	public function testLimitIsClampedToTheSupportedRange(): void {
		$this->assertSame( 100, EntityIndexPaging::clampLimit( 0 ) );
		$this->assertSame( 10, EntityIndexPaging::clampLimit( 1 ) );
		$this->assertSame( 10, EntityIndexPaging::clampLimit( 9 ) );
		$this->assertSame( 50, EntityIndexPaging::clampLimit( 50 ) );
		$this->assertSame( 100, EntityIndexPaging::clampLimit( 100 ) );
		$this->assertSame( 200, EntityIndexPaging::clampLimit( 200 ) );
		$this->assertSame( 200, EntityIndexPaging::clampLimit( 201 ) );
		$this->assertSame( 200, EntityIndexPaging::clampLimit( 9999 ) );
	}
}
