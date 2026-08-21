<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\ItemIdList;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class ItemIdListTest extends TestCase {

	public function testSplitsCommaSeparatedAndNormalizesCase(): void {
		$this->assertSame( [ 'Q5', 'Q179' ], ItemIdList::split( 'q5, Q179' ) );
	}

	public function testSplitsOnSemicolonAndWhitespace(): void {
		$this->assertSame( [ 'Q1', 'Q2', 'Q3' ], ItemIdList::split( 'Q1; Q2 Q3' ) );
	}

	public function testDedupesDuplicates(): void {
		$this->assertSame( [ 'Q5' ], ItemIdList::split( 'Q5, q5, Q5 ' ) );
	}

	public function testEmptyAndSeparatorsOnlyYieldNothing(): void {
		$this->assertSame( [], ItemIdList::split( '' ) );
		$this->assertSame( [], ItemIdList::split( ' ,;  ' ) );
	}

	public function testDoesNotValidateIdGrammar(): void {
		// Pure splitter: normalization only — grammar/existence checks are
		// the caller's job (they need the Wikibase services).
		$this->assertSame( [ 'NOT-AN-ID', 'Q1' ], ItemIdList::split( 'not-an-id, Q1' ) );
	}
}
