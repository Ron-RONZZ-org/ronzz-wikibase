<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\SourcePageSkeleton;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared Source: page body (SourcePageSkeleton).
 *
 * @covers \EmbeddableContent\Spec\SourcePageSkeleton
 * @license GPL-2.0-or-later
 */
final class SourcePageSkeletonTest extends TestCase {

	private function message(): callable {
		return static fn ( string $key ): string => $key;
	}

	public function testScholarlyArticleAbstractSection(): void {
		$html = SourcePageSkeleton::render(
			'scholarlyArticle',
			[ 'abstract' => 'The abstract text.', 'keywords' => 'alpha, beta' ],
			'ScholarlyArticle',
			$this->message()
		);
		$this->assertStringContainsString( '{{ScholarlyArticle}}', $html );
		$this->assertStringContainsString( "== Abstract ==\n\nThe abstract text.", $html );
		$this->assertStringContainsString( "== Key words ==\n\nalpha, beta", $html );
	}

	public function testBookSummarySection(): void {
		$html = SourcePageSkeleton::render(
			'book',
			[ 'summary' => 'The summary text.' ],
			'Book',
			$this->message()
		);
		$this->assertStringContainsString( "== Summary ==\n\nThe summary text.", $html );
	}

	public function testAttributionLineUsesTheContentSource(): void {
		$html = SourcePageSkeleton::render(
			'book',
			[ 'summary' => 'Text.', 'contentSources' => [ 'summary' => 'wikipedia' ] ],
			'Book',
			$this->message()
		);
		$this->assertStringContainsString(
			"''embeddablecontent-content-from-wikipedia''\n\nText.",
			$html
		);
	}

	public function testLawUsesTheContentParserFunction(): void {
		$html = SourcePageSkeleton::render( 'law', [], '', $this->message() );
		$this->assertStringContainsString( '{{Law}}', $html );
		$this->assertStringContainsString( "== Text ==\n\n{{#content:}}", $html );
	}

	public function testWebsiteIntroHasNoHeading(): void {
		$html = SourcePageSkeleton::render(
			'website',
			[ 'intro' => 'A short intro.' ],
			'Website',
			$this->message()
		);
		$this->assertStringContainsString( '{{Website}}', $html );
		$this->assertStringContainsString( 'A short intro.', $html );
		$this->assertStringNotContainsString( '== Overview ==', $html );
	}

	public function testNoContentFallsBackToTheDescription(): void {
		$html = SourcePageSkeleton::render(
			'book',
			[ 'description' => 'A description.' ],
			'Book',
			$this->message()
		);
		$this->assertStringContainsString( "== Overview ==\n\nA description.", $html );
	}

	public function testPendingMarkerIsAppended(): void {
		$html = SourcePageSkeleton::render( 'book', [], 'Book', $this->message(), '__EXTERNAL_LINK_PENDING__' );
		$this->assertStringContainsString( '<!-- __EXTERNAL_LINK_PENDING__ -->', $html );
	}

}
