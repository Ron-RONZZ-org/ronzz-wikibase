<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\GroupLabel;
use PHPUnit\Framework\TestCase;

/**
 * Auto-label building for a group of humans: member names joined with
 * commas, with "et al." replacing the tail when the label limit is reached.
 *
 * @covers \EmbeddableContent\Spec\GroupLabel
 * @license GPL-2.0-or-later
 */
class GroupLabelTest extends TestCase {

	public function testShortMemberList(): void {
		$this->assertSame(
			'Diana Daly, Kainan Jarrette',
			GroupLabel::fromMembers( [ 'Diana Daly', 'Kainan Jarrette' ] )
		);
	}

	public function testEmptyList(): void {
		$this->assertSame( '', GroupLabel::fromMembers( [] ) );
		$this->assertSame( '', GroupLabel::fromMembers( [ '  ', '' ] ) );
	}

	public function testOverflowUsesEtAlWithoutExceedingTheLimit(): void {
		$names = [];
		for ( $i = 1; $i <= 30; $i++ ) {
			$names[] = 'Author ' . $i;
		}
		$label = GroupLabel::fromMembers( $names, 60 );
		$this->assertLessThanOrEqual( 60, mb_strlen( $label ) );
		$this->assertStringEndsWith( ' et al.', $label );
		$this->assertStringStartsWith( 'Author 1, Author 2', $label );
	}

	public function testSingleLongNameIsHardTruncated(): void {
		$label = GroupLabel::fromMembers( [ str_repeat( 'A', 300 ) ], 250 );
		$this->assertSame( 250, mb_strlen( $label ) );
	}

	public function testNamesThatFitExactlyAreKept(): void {
		$label = GroupLabel::fromMembers( [ 'Ada Lovelace' ], 250 );
		$this->assertSame( 'Ada Lovelace', $label );
	}
}
