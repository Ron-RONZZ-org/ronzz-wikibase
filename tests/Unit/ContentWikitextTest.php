<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Content\ContentWikitext;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the `{{#content:}}` wikitext assembly: the function expands
 * a content item into REGULAR WIKITEXT (no `.wb-embed` chrome) — math as a
 * display `$$…$$` span, code as a stock SyntaxHighlight tag, quotations as
 * their own wikitext.
 *
 * @covers \EmbeddableContent\Content\ContentWikitext
 * @license GPL-2.0-or-later
 */
final class ContentWikitextTest extends TestCase {

	public function testMathWrapsInDisplayDelimiters(): void {
		$this->assertSame( '$$E = mc^2$$', ContentWikitext::math( 'E = mc^2' ) );
	}

	public function testMathAppendsNoteAsOwnParagraph(): void {
		$this->assertSame(
			'$$x^2$$' . "\n\n" . 'where $x$ is a real number',
			ContentWikitext::math( 'x^2', 'where $x$ is a real number' )
		);
	}

	public function testMathIgnoresBlankNote(): void {
		$this->assertSame( '$$x^2$$', ContentWikitext::math( 'x^2', "  \n  " ) );
	}

	public function testQuotationReturnsWikitextUnchanged(): void {
		$this->assertSame(
			"''italic'' [[Main Page]]",
			ContentWikitext::quotation( "''italic'' [[Main Page]]" )
		);
	}

	public function testCodeUsesSyntaxHighlightTag(): void {
		$this->assertSame(
			'<syntaxhighlight lang="python">print(1)</syntaxhighlight>',
			ContentWikitext::code( 'print(1)', 'python' )
		);
	}

	public function testCodeEscapesTheLexerAttribute(): void {
		$out = ContentWikitext::code( 'x', '"><script>alert(1)</script>' );
		$this->assertStringNotContainsString( '<script>', $out );
	}

	public function testCodeFallsBackToPreWhenTheCodeCarriesAClosingTag(): void {
		// A literal `</syntaxhighlight>` in the body would truncate the tag;
		// the escaped <pre> keeps the content intact instead.
		$out = ContentWikitext::code( 'a</syntaxhighlight>b', 'text' );
		$this->assertStringStartsWith( '<pre>', $out );
		$this->assertStringContainsString( 'a&lt;/syntaxhighlight&gt;b', $out );
		$this->assertStringNotContainsString( '<syntaxhighlight', $out );
	}

	public function testCodeDefaultsEmptyLexerToText(): void {
		$this->assertStringContainsString( 'lang="text"', ContentWikitext::code( 'x', '   ' ) );
	}
}
