<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Tests\Unit\Spec;

use EmbeddableContent\Spec\ArchiveCover;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Spec\ArchiveCover
 * @license GPL-2.0-or-later
 */
class ArchiveCoverTest extends TestCase {

	public function testStripsTheKnownClassSuffixOnce(): void {
		$this->assertSame(
			'A review of the evidence',
			ArchiveCover::title( 'A review of the evidence (Webpage)', 'Webpage' )
		);
	}

	public function testKeepsAParentheticalThatIsNotTheClass(): void {
		$this->assertSame(
			'The Art of War (annotated)',
			ArchiveCover::title( 'The Art of War (annotated)', 'Book' )
		);
	}

	public function testNoClassKeepsTheTitleVerbatim(): void {
		$this->assertSame(
			'Plain title',
			ArchiveCover::title( 'Plain title', '' )
		);
	}

	public function testSuffixExtractsTheTrailingParenthetical(): void {
		$this->assertSame( 'Webpage', ArchiveCover::suffix( 'A title (Webpage)' ) );
		$this->assertSame( '', ArchiveCover::suffix( 'A title' ) );
		$this->assertSame( '', ArchiveCover::suffix( '(only parentheses)' ) );
	}
}
