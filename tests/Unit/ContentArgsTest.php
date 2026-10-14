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

	public function testGranularNoteFlagsAreReadSeparately(): void {
		// noPreNote suppresses the pre-content note only; noPostNote the
		// post-content note only; noNote both.
		$args = [ 'Q42', 'noPreNote' ];
		$this->assertTrue( ContentArgs::noPreNote( $args ) );
		$this->assertFalse( ContentArgs::noPostNote( $args ) );
		$this->assertFalse( ContentArgs::noNote( $args ) );

		$args = [ 'Q42', '  noPostNote ' ];
		$this->assertFalse( ContentArgs::noPreNote( $args ) );
		$this->assertTrue( ContentArgs::noPostNote( $args ) );
		$this->assertFalse( ContentArgs::noNote( $args ) );

		// The flags combine.
		$args = [ 'Q42', 'noPreNote', 'noPostNote' ];
		$this->assertTrue( ContentArgs::noPreNote( $args ) );
		$this->assertTrue( ContentArgs::noPostNote( $args ) );
	}

	public function testGranularNoteFlagsAreNotReadFromTheItemIdPosition(): void {
		$this->assertFalse( ContentArgs::noPreNote( [ 'noPreNote' ] ) );
		$this->assertFalse( ContentArgs::noPostNote( [ 'noPostNote' ] ) );
	}

	public function testLanguagesReadsTheCodesAfterTheItemId(): void {
		$this->assertSame( [ 'fr', 'en' ], ContentArgs::languages( [ 'Q42', 'fr', 'en' ] ) );
		// The no-id form: {{#content:|fr|en}}.
		$this->assertSame( [ 'fr', 'en' ], ContentArgs::languages( [ '', 'fr', 'en' ] ) );
		// Whitespace/case normalize; duplicates collapse in order.
		$this->assertSame( [ 'fr' ], ContentArgs::languages( [ 'Q42', ' FR ', 'fr' ] ) );
	}

	public function testLanguagesSkipsFlagsAndNonCodes(): void {
		// The math-note flags and anything that is not a language code are
		// not treated as translation languages.
		$this->assertSame( [], ContentArgs::languages( [ 'Q42', 'noNote' ] ) );
		$this->assertSame( [], ContentArgs::languages( [ 'Q42', 'noPreNote' ] ) );
		$this->assertSame( [], ContentArgs::languages( [ 'Q42', 'noPostNote' ] ) );
		$this->assertSame( [ 'fr' ], ContentArgs::languages( [ 'Q42', 'noNote', 'noPreNote', 'fr' ] ) );
		$this->assertSame( [], ContentArgs::languages( [ 'Q42', 'not a code!' ] ) );
		$this->assertSame( [], ContentArgs::languages( [ 'fr' ] ) );
	}
}
