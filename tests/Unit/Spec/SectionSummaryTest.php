<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\SectionSummary;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the auto-inferred section-edit summary
 * (Spec\SectionSummary).
 *
 * @covers \EmbeddableContent\Spec\SectionSummary
 * @license GPL-2.0-or-later
 */
final class SectionSummaryTest extends TestCase {

	public function testInfersFromATopLevelHeading(): void {
		$this->assertSame(
			'/* Vandermonde */',
			SectionSummary::infer( "Intro.\n\n== Vandermonde ==\n\nBody." )
		);
	}

	public function testTopmostHeadingWinsOverNestedSubsections(): void {
		$this->assertSame(
			'/* Algebra */',
			SectionSummary::infer( "== Algebra ==\n\n=== Nested ===\n\nBody." )
		);
	}

	public function testLevelOneHeadingIsRecognized(): void {
		$this->assertSame( '/* Title */', SectionSummary::infer( "= Title =\n\nBody." ) );
	}

	public function testHeadingMarkupIsKeptAsCoreKeepsIt(): void {
		$this->assertSame(
			"/* ''italic'' heading */",
			SectionSummary::infer( "== ''italic'' heading ==\n\nBody." )
		);
	}

	public function testNoHeadingReturnsNull(): void {
		$this->assertNull( SectionSummary::infer( "Just prose.\n\nMore prose." ) );
		$this->assertNull( SectionSummary::infer( '' ) );
	}

	public function testMismatchedEqualsFollowsCoreGrammar(): void {
		// Core's grammar backtracks the opening run, so `== A =` is an H1
		// whose text is `= A` (verified against action=parse). The inferred
		// summary must match core's own parse, not an idealised rule.
		$this->assertSame( '/* = A */', SectionSummary::infer( "== A =\n\nBody." ) );
	}

	public function testLongHeadingIsTruncatedButKeepsTheDelimiters(): void {
		$long = str_repeat( 'a', 600 );
		$summary = SectionSummary::infer( "== $long ==\n" );
		$this->assertNotNull( $summary );
		$this->assertSame( SectionSummary::COMMENT_LIMIT, strlen( $summary ) );
		$this->assertSame( '/* ' . str_repeat( 'a', SectionSummary::COMMENT_LIMIT - 6 ) . ' */', $summary );
	}
}
