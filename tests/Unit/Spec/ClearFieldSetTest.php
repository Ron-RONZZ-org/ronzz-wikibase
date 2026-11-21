<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\ClearFieldSet;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Spec\ClearFieldSet
 * @license GPL-2.0-or-later
 */
final class ClearFieldSetTest extends TestCase {

	public function testReturnsOnlyBlankRenderedFields(): void {
		$data = [
			'license' => 'Q1',
			'author' => '  ',
			'sourceUrl' => '',
			'note' => 'kept',
		];
		$this->assertSame(
			[ 'author', 'sourceUrl', 'date' ],
			ClearFieldSet::blank( $data, [ 'license', 'author', 'sourceUrl', 'date' ] )
		);
	}

	public function testAbsentFieldsAreBlank(): void {
		$this->assertSame( [ 'author' ], ClearFieldSet::blank( [], [ 'author' ] ) );
	}

}
