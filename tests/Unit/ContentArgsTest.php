<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\ParserFunctions\ContentArgs;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {{#content:}} argument parsing.
 *
 * @license GPL-2.0-or-later
 */
class ContentArgsTest extends TestCase {

	public function testNoNoteFlagIsReadFromTheFlagsAfterTheItemId(): void {
		$this->assertFalse( ContentArgs::noNote( [] ) );
		$this->assertFalse( ContentArgs::noNote( [ 'Q42' ] ) );
		$this->assertTrue( ContentArgs::noNote( [ 'Q42', 'noNote' ] ) );
		// Whitespace and case are normalized.
		$this->assertTrue( ContentArgs::noNote( [ 'Q42', '  noNote ' ] ) );
		$this->assertTrue( ContentArgs::noNote( [ 'Q42', 'NONOTE' ] ) );
		// The no-id form: {{#content:|noNote}}.
		$this->assertTrue( ContentArgs::noNote( [ '', 'noNote' ] ) );
	}

	public function testNoNoteIsNotReadFromTheItemIdPosition(): void {
		// The first argument is the item id; a literal "noNote" there is not
		// a flag (it simply fails the item-id parse and falls back to the
		// page's sitelinked item).
		$this->assertFalse( ContentArgs::noNote( [ 'noNote' ] ) );
		$this->assertFalse( ContentArgs::noNote( [ 'Q42', 'other' ] ) );
	}
}
