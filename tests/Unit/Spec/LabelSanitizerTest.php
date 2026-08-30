<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Tests\Unit\Spec;

use EmbeddableContent\Spec\LabelSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Spec\LabelSanitizer
 * @license GPL-2.0-or-later
 */
class LabelSanitizerTest extends TestCase {

	public function testStripsOpenAlexItalicMarkup(): void {
		$this->assertSame(
			'Planck 2018 results',
			LabelSanitizer::stripMarkup( '<i>Planck</i>  2018 results' )
		);
	}

	public function testStripsEmAndBold(): void {
		$this->assertSame(
			'On the Origin of Species',
			LabelSanitizer::stripMarkup( '<em>On the</em> <b>Origin</b> of Species' )
		);
	}

	public function testDecodesEntitiesBeforeStripping(): void {
		// "&lt;i&gt;" must not survive as literal markup after stripping.
		$this->assertSame(
			'x',
			LabelSanitizer::stripMarkup( '&lt;i&gt;x&lt;/i&gt;' )
		);
		$this->assertSame(
			'AT&T',
			LabelSanitizer::stripMarkup( 'AT&amp;T' )
		);
	}

	public function testCollapsesWhitespaceRuns(): void {
		$this->assertSame(
			'Gravitational lensing review',
			LabelSanitizer::stripMarkup( "Gravitational \t lensing \n review" )
		);
	}

	public function testPlainTextIsUnchanged(): void {
		$this->assertSame(
			'The Hobbit',
			LabelSanitizer::stripMarkup( 'The Hobbit' )
		);
	}

	public function testEmptyInputStaysEmpty(): void {
		$this->assertSame( '', LabelSanitizer::stripMarkup( '' ) );
		$this->assertSame( '', LabelSanitizer::stripMarkup( '  <i></i>  ' ) );
	}

	public function testUnclosedTagDoesNotEatTheText(): void {
		// "<[^>]*>" requires a closing ">"; a stray "<" (e.g. "C++ < C#")
		// is legal in a label term and is left alone here (the page-title
		// layer is responsible for title legality).
		$this->assertSame(
			'a < b',
			LabelSanitizer::stripMarkup( 'a < b' )
		);
	}

}
