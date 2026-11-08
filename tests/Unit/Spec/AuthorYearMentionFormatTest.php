<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\AuthorYearMention;
use PHPUnit\Framework\TestCase;

/**
 * Author-count formatting of the `[[Source:X|name-author]]` caption.
 *
 * The item lookup itself is exercised live; this test pins the pure
 * family-list rule: one family on its own, two or three hyphenated, four
 * or more shortened to the first family + "et al.".
 *
 * @covers \EmbeddableContent\Spec\AuthorYearMention::formatFamilies
 * @license GPL-2.0-or-later
 */
class AuthorYearMentionFormatTest extends TestCase {

	public function testSingleAuthor(): void {
		$this->assertSame(
			'Zwaigenbaum (2015)',
			AuthorYearMention::formatFamilies( [ 'Zwaigenbaum' ], '2015' )
		);
	}

	public function testTwoAuthorsAreHyphenated(): void {
		$this->assertSame(
			'Moro-Phelps (2019)',
			AuthorYearMention::formatFamilies( [ 'Moro', 'Phelps' ], '2019' )
		);
	}

	public function testThreeAuthorsAreHyphenated(): void {
		$this->assertSame(
			'Zwaigenbaum-Bauman-Stone (2015)',
			AuthorYearMention::formatFamilies(
				[ 'Zwaigenbaum', 'Bauman', 'Stone' ],
				'2015'
			)
		);
	}

	public function testFourAuthorsUseEtAl(): void {
		$this->assertSame(
			'Zwaigenbaum et al. (2015)',
			AuthorYearMention::formatFamilies(
				[ 'Zwaigenbaum', 'Bauman', 'Stone', 'Yirmiya' ],
				'2015'
			)
		);
	}

	public function testLongAuthorListUsesOnlyTheFirstFamily(): void {
		$this->assertSame(
			'Zwaigenbaum et al. (2015)',
			AuthorYearMention::formatFamilies(
				[
					'Zwaigenbaum', 'Bauman', 'Stone', 'Yirmiya', 'Estes',
					'Hansen', 'McPartland', 'Natowicz', 'Choueiri', 'Fein',
				],
				'2015'
			)
		);
	}

	public function testEmptyFamiliesAreIgnored(): void {
		$this->assertSame(
			'Moro-Phelps (2019)',
			AuthorYearMention::formatFamilies( [ 'Moro', '', 'Phelps' ], '2019' )
		);
	}

	public function testNoFamilyReturnsEmptyCaption(): void {
		$this->assertSame( '', AuthorYearMention::formatFamilies( [], '2015' ) );
		$this->assertSame( '', AuthorYearMention::formatFamilies( [ '', ' ' ], '2015' ) );
	}

}
