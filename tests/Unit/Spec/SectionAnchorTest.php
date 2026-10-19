<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\SectionAnchor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Spec\SectionAnchor
 * @license GPL-2.0-or-later
 */
class SectionAnchorTest extends TestCase {

	public function testMixedMathIsStripped(): void {
		$this->assertSame(
			'Symmetric_groups',
			SectionAnchor::fromHeadingId( 'Symmetric_groups_$\mathcal{S}_{n}$' )
		);
		$this->assertSame(
			'Identity_permutation:',
			SectionAnchor::fromHeadingId( 'Identity_permutation:_$\sigma_{id}(s)=s$' )
		);
		$this->assertSame(
			'Inverse_permutation',
			SectionAnchor::fromHeadingId( 'Inverse_permutation_$\sigma^{-1}$' )
		);
		$this->assertSame(
			'Costs',
			SectionAnchor::fromHeadingId( 'Costs_$x+y$' )
		);
	}

	public function testEveryMathSegmentIsStripped(): void {
		$this->assertSame(
			'elements_permutations',
			SectionAnchor::fromHeadingId( '$n$_elements_$\Rightarrow_$_$n!$_permutations' )
		);
	}

	public function testMathOnlyKeepsTheNormalisedMath(): void {
		// Stripping would leave nothing, so the math is kept; braces become
		// underscores so the fragment stays linkable.
		$this->assertSame(
			'$\operatorname_sign_(\sigma)$',
			SectionAnchor::fromHeadingId( '$\operatorname{sign}(\sigma)$' )
		);
		$this->assertSame(
			'$\mathcal_S_n_$',
			SectionAnchor::fromHeadingId( '$\mathcal{S}_{n}$' )
		);
	}

	public function testNoMathIsUnchanged(): void {
		$this->assertSame( 'Plain_heading', SectionAnchor::fromHeadingId( 'Plain_heading' ) );
		$this->assertSame( 'Function_(math)', SectionAnchor::fromHeadingId( 'Function_(math)' ) );
	}

	public function testIllegalCharactersBecomeUnderscoresAndCollapse(): void {
		$this->assertSame( 'A_B', SectionAnchor::fromHeadingId( 'A_{}B' ) );
		$this->assertSame( 'A_B', SectionAnchor::fromHeadingId( 'A_#_B' ) );
		$this->assertSame( 'A_B', SectionAnchor::fromHeadingId( 'A_<>_B' ) );
		$this->assertSame( 'A_B', SectionAnchor::fromHeadingId( 'A_[|]_B' ) );
	}

	public function testTrimmedUnderscores(): void {
		$this->assertSame( 'Heading', SectionAnchor::fromHeadingId( '_Heading_' ) );
		$this->assertSame( 'Heading', SectionAnchor::fromHeadingId( '__Heading__' ) );
	}

	public function testEscapedDollarIsNotMath(): void {
		// The math guide's price convention: `\$5` is a literal dollar.
		$this->assertSame( 'Costs_\\$5', SectionAnchor::fromHeadingId( 'Costs_\\$5' ) );
	}

	public function testUnicodeIsPreserved(): void {
		$this->assertSame( 'Café_au_lait', SectionAnchor::fromHeadingId( 'Café_au_lait' ) );
	}

	public function testEmptyIdYieldsEmptyAnchor(): void {
		$this->assertSame( '', SectionAnchor::fromHeadingId( '' ) );
		$this->assertSame( '', SectionAnchor::fromHeadingId( '   ' ) );
	}

}
