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

	// ---------------------------------------------------- normalizeForTitle

	public function testNormalizeForTitleKeepsPlainLabels(): void {
		$this->assertSame( 'Albert Einstein', LabelSanitizer::normalizeForTitle( 'Albert Einstein' ) );
		// "/" is accepted by MediaWiki (it only makes the title a subpage).
		$this->assertSame( 'AC/DC', LabelSanitizer::normalizeForTitle( 'AC/DC' ) );
		$this->assertSame( 'Café', LabelSanitizer::normalizeForTitle( 'Café' ) );
	}

	public function testNormalizeForTitleReplacesForbiddenCharacters(): void {
		// MediaWiki forbids # < > [ ] { } | in titles (Title::isValid).
		$this->assertSame( 'A-B', LabelSanitizer::normalizeForTitle( 'A|B' ) );
		$this->assertSame( 'A-B', LabelSanitizer::normalizeForTitle( 'A<B' ) );
		$this->assertSame( 'A-B', LabelSanitizer::normalizeForTitle( 'A{B}' ) );
		$this->assertSame( 'A-B', LabelSanitizer::normalizeForTitle( 'A[B]' ) );
		$this->assertSame(
			'C- programming language',
			LabelSanitizer::normalizeForTitle( 'C# programming language' )
		);
	}

	public function testNormalizeForTitleStripsMarkupFirst(): void {
		$this->assertSame(
			'Foo baz',
			LabelSanitizer::normalizeForTitle( 'Foo <bar> baz' )
		);
	}

	public function testNormalizeForTitleCollapsesAndTrims(): void {
		$this->assertSame( 'Foo', LabelSanitizer::normalizeForTitle( '  ---Foo---  ' ) );
		$this->assertSame( 'A - B', LabelSanitizer::normalizeForTitle( 'A  |  B' ) );
	}

	public function testNormalizeForTitleOfAllForbiddenIsEmpty(): void {
		// Nothing usable remains — the caller keeps the item-only fallback.
		$this->assertSame( '', LabelSanitizer::normalizeForTitle( '###' ) );
		$this->assertSame( '', LabelSanitizer::normalizeForTitle( '<i></i>' ) );
	}

	// ---------------------------------------- stripParentheticalSuffix

	public function testStripParentheticalSuffixDropsTheClassSuffix(): void {
		$this->assertSame(
			'Méditations poétiques',
			LabelSanitizer::stripParentheticalSuffix( 'Méditations poétiques (Book)' )
		);
		$this->assertSame(
			'The Hobbit',
			LabelSanitizer::stripParentheticalSuffix( 'The Hobbit (Book)' )
		);
		$this->assertSame(
			'Example Domain',
			LabelSanitizer::stripParentheticalSuffix( 'Example Domain (Website)' )
		);
	}

	public function testStripParentheticalSuffixKeepsInnerParentheses(): void {
		// A parenthetical that is not at the end is part of the label.
		$this->assertSame(
			'Poems (Second Series)',
			LabelSanitizer::stripParentheticalSuffix( 'Poems (Second Series) (Book)' )
		);
		$this->assertSame(
			'A (B) C',
			LabelSanitizer::stripParentheticalSuffix( 'A (B) C' )
		);
	}

	public function testStripParentheticalSuffixLeavesPlainLabelsAlone(): void {
		$this->assertSame( 'Albert Einstein', LabelSanitizer::stripParentheticalSuffix( 'Albert Einstein' ) );
		$this->assertSame( '', LabelSanitizer::stripParentheticalSuffix( '' ) );
		$this->assertSame( 'The Hobbit', LabelSanitizer::stripParentheticalSuffix( '  The Hobbit  ' ) );
	}

	public function testStripParentheticalSuffixNeverEmptiesTheLabel(): void {
		// A label that is only a parenthetical is kept as-is.
		$this->assertSame( '(disambiguation)', LabelSanitizer::stripParentheticalSuffix( '(disambiguation)' ) );
	}

}
