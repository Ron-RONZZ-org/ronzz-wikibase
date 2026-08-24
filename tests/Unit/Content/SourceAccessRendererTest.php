<?php

declare( strict_types = 1 );

namespace Tests\Unit\Content;

use EmbeddableContent\Content\SourceAccessRenderer;
use PHPUnit\Framework\TestCase;

/**
 * SourceAccessRenderer — the "Access" infobox cell of a Source: page
 * (ADR docs/decisions/source-access-rendering.md).
 *
 * Pure branching logic: a stored copy (file) wins, then a non-direct
 * access URL, then the localized "N/A" fallback. File names link to
 * Special:SourceFile with the owning item id; URLs render as clickable
 * external links.
 *
 * @license GPL-2.0-or-later
 */
final class SourceAccessRendererTest extends TestCase {

	public function testFileWinsOverAccessUrl(): void {
		$cell = SourceAccessRenderer::render(
			[ 'File:War and Peace.pdf' ],
			[ 'https://example.org/landing' ],
			'Q42',
			'N/A'
		);
		$this->assertSame(
			'[[Special:SourceFile?item=Q42&file=File%3AWar%20and%20Peace.pdf|File:War and Peace.pdf]]',
			$cell
		);
	}

	public function testFileParamIsUrlEncoded(): void {
		$cell = SourceAccessRenderer::render(
			[ 'File:Q&A.pdf' ],
			[],
			'Q7',
			'N/A'
		);
		$this->assertStringContainsString( 'file=File%3AQ%26A.pdf', $cell );
		// The label keeps the human-readable title.
		$this->assertStringContainsString( '|File:Q&A.pdf]]', $cell );
	}

	public function testAccessUrlRendersClickableLink(): void {
		$cell = SourceAccessRenderer::render(
			[],
			[ 'https://example.org/full-work' ],
			'Q42',
			'N/A'
		);
		$this->assertSame( '[https://example.org/full-work https://example.org/full-work]', $cell );
	}

	public function testAccessUrlWithSpacesIsEncoded(): void {
		$cell = SourceAccessRenderer::render(
			[],
			[ 'https://example.org/a b' ],
			'Q42',
			'N/A'
		);
		$this->assertSame( '[https://example.org/a%20b https://example.org/a%20b]', $cell );
	}

	public function testNoAccessRendersNa(): void {
		$cell = SourceAccessRenderer::render( [], [], 'Q42', 'N/A' );
		$this->assertSame( 'N/A', $cell );
	}

	public function testLocalizedNaTextIsUsed(): void {
		$cell = SourceAccessRenderer::render( [], [], 'Q42', 'S.O.' );
		$this->assertSame( 'S.O.', $cell );
	}

	public function testFirstFileWinsWhenMultiple(): void {
		$cell = SourceAccessRenderer::render(
			[ 'File:A.pdf', 'File:B.pdf' ],
			[],
			'Q1',
			'N/A'
		);
		$this->assertStringContainsString( 'file=File%3AA.pdf', $cell );
	}

	public function testFirstAccessUrlWinsWhenMultiple(): void {
		$cell = SourceAccessRenderer::render(
			[],
			[ 'https://example.org/one', 'https://example.org/two' ],
			'Q1',
			'N/A'
		);
		$this->assertSame( '[https://example.org/one https://example.org/one]', $cell );
	}

	public function testHostileTitleCharsAreStrippedFromLabel(): void {
		// A DB-backed file title must not be able to inject link syntax.
		$cell = SourceAccessRenderer::render(
			[ 'File:A]]B|C.pdf' ],
			[],
			'Q1',
			'N/A'
		);
		// The LABEL (after the | separator) must be free of raw link syntax —
		// the closing ]] of the wikilink itself is expected.
		$label = substr( $cell, strpos( $cell, '|' ) + 1 );
		$this->assertSame( 'File:ABC.pdf]]', $label );
	}

	public function testEmptyValuesRenderNa(): void {
		$this->assertSame( 'N/A', SourceAccessRenderer::render( [ '' ], [ '' ], 'Q42', 'N/A' ) );
	}
}
