<?php

declare( strict_types = 1 );

namespace Tests\Unit\Content;

use EmbeddableContent\Content\SourceAccessRenderer;
use PHPUnit\Framework\TestCase;

/**
 * SourceAccessRenderer — the "Access" infobox cell of a Source: page
 * (multi-access).
 *
 * Pure formatting: URL entries are labelled url1/url2…, file entries by
 * extension, every link opens in a new tab, and the login hint appears when
 * hidden entries exist.
 *
 * @license GPL-2.0-or-later
 */
final class SourceAccessRendererTest extends TestCase {

	public function testNoAccessRendersNa(): void {
		$this->assertSame(
			'N/A',
			SourceAccessRenderer::render( [], 'N/A', false, '', 'Login' )
		);
	}

	public function testLocalizedNaTextIsUsed(): void {
		$this->assertSame(
			'S.O.',
			SourceAccessRenderer::render( [], 'S.O.', false, '', 'Login' )
		);
	}

	public function testUrlEntriesAreNumbered(): void {
		$cell = SourceAccessRenderer::render(
			[
				[ 'kind' => 'url', 'href' => 'https://example.org/one', 'label' => '' ],
				[ 'kind' => 'url', 'href' => 'https://example.org/two', 'label' => '' ],
			],
			'N/A',
			false,
			'',
			'Login'
		);
		$this->assertStringContainsString( '>url1</a>', $cell );
		$this->assertStringContainsString( '>url2</a>', $cell );
		$this->assertStringContainsString( 'href="https://example.org/one"', $cell );
		$this->assertStringContainsString( 'target="_blank"', $cell );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $cell );
	}

	public function testFileLabelsUseExtension(): void {
		$cell = SourceAccessRenderer::render(
			[ [ 'kind' => 'file', 'href' => 'https://wiki/Special:SourceFile?file=X.pdf', 'label' => 'pdf' ] ],
			'N/A',
			false,
			'',
			'Login'
		);
		$this->assertStringContainsString( '>pdf</a>', $cell );
		$this->assertStringContainsString( 'Special:SourceFile', $cell );
	}

	public function testMixedEntriesJoinWithPipe(): void {
		$cell = SourceAccessRenderer::render(
			[
				[ 'kind' => 'url', 'href' => 'https://example.org', 'label' => '' ],
				[ 'kind' => 'file', 'href' => 'https://wiki/Special:SourceFile?file=X.epub', 'label' => 'epub' ],
			],
			'N/A',
			false,
			'',
			'Login'
		);
		$this->assertStringContainsString( ' | ', $cell );
		$this->assertStringContainsString( '>url1</a>', $cell );
		$this->assertStringContainsString( '>epub</a>', $cell );
	}

	public function testFileLanguagesGroupByExtension(): void {
		$cell = SourceAccessRenderer::render(
			[
				[ 'kind' => 'file', 'href' => 'https://wiki/Special:SourceFile?file=X.pdf&l=en', 'label' => 'pdf', 'language' => 'en' ],
				[ 'kind' => 'file', 'href' => 'https://wiki/Special:SourceFile?file=X-fr.pdf&l=fr', 'label' => 'pdf', 'language' => 'fr' ],
			],
			'N/A',
			false,
			'',
			'Login'
		);
		$this->assertStringContainsString( 'PDF: ', $cell );
		$this->assertStringContainsString( '>en</a>', $cell );
		$this->assertStringContainsString( '>fr</a>', $cell );
		$this->assertStringContainsString( '>en</a>|<a', $cell );
	}

	public function testFileLanguageGroupDeduplicatesLanguages(): void {
		$cell = SourceAccessRenderer::render(
			[
				[ 'kind' => 'file', 'href' => 'https://wiki/a.pdf', 'label' => 'pdf', 'language' => 'en' ],
				[ 'kind' => 'file', 'href' => 'https://wiki/b.pdf', 'label' => 'pdf', 'language' => 'en' ],
			],
			'N/A',
			false,
			'',
			'Login'
		);
		$this->assertSame( 1, substr_count( $cell, '>en</a>' ) );
	}

	public function testUrlEntryCarriesItsLanguage(): void {
		$cell = SourceAccessRenderer::render(
			[ [ 'kind' => 'url', 'href' => 'https://example.org', 'label' => '', 'language' => 'fr' ] ],
			'N/A',
			false,
			'',
			'Login'
		);
		$this->assertStringContainsString( 'url1: ', $cell );
		$this->assertStringContainsString( '>fr</a>', $cell );
	}

	public function testLegacyFileWithoutLanguageKeepsPlainExtensionLink(): void {
		$cell = SourceAccessRenderer::render(
			[ [ 'kind' => 'file', 'href' => 'https://wiki/x.pdf', 'label' => 'pdf', 'language' => '' ] ],
			'N/A',
			false,
			'',
			'Login'
		);
		$this->assertStringContainsString( '>pdf</a>', $cell );
		$this->assertStringNotContainsString( 'PDF:', $cell );
	}

	public function testHiddenEntriesAppendLoginHint(): void {
		$cell = SourceAccessRenderer::render(
			[ [ 'kind' => 'url', 'href' => 'https://example.org', 'label' => '' ] ],
			'N/A',
			true,
			'https://wiki/Special:UserLogin',
			'Login to view additional access modes'
		);
		$this->assertStringContainsString( 'Special:UserLogin', $cell );
		$this->assertStringContainsString( 'Login to view additional access modes', $cell );
	}

	public function testHiddenEntriesWithoutLoginUrlOmitHint(): void {
		$cell = SourceAccessRenderer::render(
			[ [ 'kind' => 'url', 'href' => 'https://example.org', 'label' => '' ] ],
			'N/A',
			true,
			'',
			'Login to view additional access modes'
		);
		$this->assertStringNotContainsString( 'Login to view', $cell );
	}

	public function testHostileHrefLabelIsEscaped(): void {
		$cell = SourceAccessRenderer::render(
			[ [ 'kind' => 'file', 'href' => 'https://wiki/?x=<script>"', 'label' => '<b>pdf</b>' ] ],
			'N/A',
			false,
			'',
			'Login'
		);
		$this->assertStringNotContainsString( '<script>', $cell );
		$this->assertStringNotContainsString( '<b>', $cell );
		$this->assertStringContainsString( '&lt;b&gt;pdf&lt;/b&gt;', $cell );
	}

	public function testEmptyHrefIsSkipped(): void {
		$this->assertSame(
			'N/A',
			SourceAccessRenderer::render(
				[ [ 'kind' => 'url', 'href' => '', 'label' => '' ] ],
				'N/A',
				false,
				'',
				'Login'
			)
		);
	}
}
