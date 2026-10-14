<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\AppendSectionText;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the "Add section" append assembly (the text contract of
 * AppendSectionAction).
 *
 * @covers \EmbeddableContent\Spec\AppendSectionText
 * @license GPL-2.0-or-later
 */
final class AppendSectionTextTest extends TestCase {

	public function testAppendsWithOneBlankLineSeparator(): void {
		$this->assertSame(
			"First paragraph.\n\nNew content.",
			AppendSectionText::append( 'First paragraph.', 'New content.' )
		);
	}

	public function testTrimsTheTrailingWhitespaceOfTheCurrentContent(): void {
		// However the page ended (trailing blanks/newlines), the separator is
		// exactly one blank line.
		$this->assertSame(
			"Body.\n\nAdded.",
			AppendSectionText::append( "Body.\n\n\n  \n", 'Added.' )
		);
	}

	public function testTrimsTheAddedText(): void {
		$this->assertSame(
			"Body.\n\nAdded.",
			AppendSectionText::append( 'Body.', "  \n Added. \n" )
		);
	}

	public function testEmptyPageIsJustTheAddedText(): void {
		$this->assertSame( 'Added.', AppendSectionText::append( '', 'Added.' ) );
		$this->assertSame( 'Added.', AppendSectionText::append( "  \n\n ", 'Added.' ) );
	}

	public function testBlankAddedTextIsANoOp(): void {
		$this->assertSame( 'Body.', AppendSectionText::append( 'Body.', '' ) );
		$this->assertSame( 'Body.', AppendSectionText::append( 'Body.', "  \n " ) );
	}
}
