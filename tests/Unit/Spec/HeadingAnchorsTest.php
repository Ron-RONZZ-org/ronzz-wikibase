<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\HeadingAnchors;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Spec\HeadingAnchors
 * @license GPL-2.0-or-later
 */
class HeadingAnchorsTest extends TestCase {

	public function testMixedMathHeadingGetsAnAutomaticAnchorAfterTheLegacySpan(): void {
		$html = <<<'HTML'
<h3 id="Symmetric_groups_$\mathcal{S}_{n}$"><span id="Symmetric_groups_.24.5Cmathcal.7BS.7D_.7Bn.7D.24"></span>Symmetric groups $\mathcal{S}_{n}$</h3>
HTML;
		$out = HeadingAnchors::inject( $html );
		$this->assertStringContainsString(
			'<span id="Symmetric_groups_.24.5Cmathcal.7BS.7D_.7Bn.7D.24"></span>'
			. '<span class="wb-section-auto-anchor" id="Symmetric_groups"></span>',
			$out
		);
	}

	public function testExplicitAnchorIsLeftAlone(): void {
		$html = <<<'HTML'
<h3 id="Anchored_$x$"><span id="Anchored_.24x.24"></span><span id="my_anchor"></span>Anchored $x$</h3>
HTML;
		$out = HeadingAnchors::inject( $html );
		$this->assertSame( $html, $out );
		$this->assertStringNotContainsString( 'wb-section-auto-anchor', $out );
	}

	public function testMathOnlyHeadingKeepsTheNormalisedMath(): void {
		$html = <<<'HTML'
<h3 id="$\operatorname{sign}(\sigma)$"><span id=".24.5Coperatorname.7Bsign.7D.28.5Csigma.29.24"></span>$\operatorname{sign}(\sigma)$</h3>
HTML;
		$out = HeadingAnchors::inject( $html );
		$this->assertStringContainsString(
			'<span class="wb-section-auto-anchor" id="$\operatorname_sign_(\sigma)$"></span>',
			$out
		);
	}

	public function testPlainHeadingIsUnchanged(): void {
		$html = '<h2 id="Plain_heading">Plain heading</h2>';
		$this->assertSame( $html, HeadingAnchors::inject( $html ) );
	}

	public function testCollidingAnchorGetsASuffix(): void {
		$html = <<<'HTML'
<h2 id="A_$x$"><span id="A_.24x.24"></span>A $x$</h2><h2 id="A">A</h2>
HTML;
		$out = HeadingAnchors::inject( $html );
		$this->assertStringContainsString( 'class="wb-section-auto-anchor" id="A_2"', $out );
	}

	public function testHtmlEntitiesAreDecodedAndReEncoded(): void {
		$html = <<<'HTML'
<h2 id="A&amp;B_$x$"><span id="A.26B_.24x.24"></span>A&amp;B $x$</h2>
HTML;
		$out = HeadingAnchors::inject( $html );
		$this->assertStringContainsString( 'class="wb-section-auto-anchor" id="A&amp;B"', $out );
	}

	public function testInjectionIsIdempotent(): void {
		$html = <<<'HTML'
<h3 id="Symmetric_groups_$\mathcal{S}_{n}$"><span id="Symmetric_groups_.24.5Cmathcal.7BS.7D_.7Bn.7D.24"></span>Symmetric groups $\mathcal{S}_{n}$</h3>
HTML;
		$once = HeadingAnchors::inject( $html );
		$this->assertSame( $once, HeadingAnchors::inject( $once ) );
	}

	public function testNoHeadingsIsUnchanged(): void {
		$html = '<p>No headings here.</p>';
		$this->assertSame( $html, HeadingAnchors::inject( $html ) );
	}

}
