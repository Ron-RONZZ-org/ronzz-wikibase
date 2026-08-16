<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WikibaseCitation\CitationFormatter;

/**
 * End-to-end formatting tests with real citeproc-php + the vendored CSL
 * styles (requires composer dev deps, no MediaWiki).
 *
 * @license GPL-2.0-or-later
 */
class CitationFormatterTest extends TestCase {

	private const STYLE_DIR = __DIR__ . '/../../extensions/WikibaseCitation/styles';

	private CitationFormatter $formatter;

	protected function setUp(): void {
		$this->formatter = new CitationFormatter( self::STYLE_DIR );
	}

	private function sampleCsl(): array {
		return [
			'type' => 'article',
			'title' => 'The Analytical Engine has no pretensions whatever to originate anything',
			'author' => [ [ 'given' => 'Ada', 'family' => 'Lovelace' ] ],
			'container-title' => 'Notes by the Translator',
			'issued' => [ 'date-parts' => [ [ 1843 ] ] ],
			'URL' => 'https://wikibase.ronzz.org/wiki/Item:Q5',
		];
	}

	public function testJsonStyleReturnsCslJson(): void {
		$out = $this->formatter->format( $this->sampleCsl(), 'json' );
		$this->assertJson( $out );
		$decoded = json_decode( $out, true );
		$this->assertSame( 'article', $decoded['type'] );
	}

	public function testApaStyleRendersAuthorAndTitle(): void {
		$out = $this->formatter->format( $this->sampleCsl(), 'apa', 'text' );
		$this->assertStringContainsString( 'Lovelace', $out );
		$this->assertStringContainsString( 'Analytical Engine', $out );
	}

	public function testApaHtmlFormatKeepsMarkup(): void {
		$out = $this->formatter->format( $this->sampleCsl(), 'apa', 'html' );
		$this->assertStringContainsString( '<', $out );
	}

	public function testVancouverStyleRenders(): void {
		$out = $this->formatter->format( $this->sampleCsl(), 'vancouver', 'text' );
		$this->assertStringContainsString( 'Lovelace', $out );
		$this->assertStringContainsString( 'Analytical Engine', $out );
	}

	public function testBibtexAndRisArePlainText(): void {
		$this->assertStringStartsWith( '@article{', $this->formatter->format( $this->sampleCsl(), 'bibtex' ) );
		$this->assertStringStartsWith( 'TY  - JOUR', $this->formatter->format( $this->sampleCsl(), 'ris' ) );
	}

	public function testUnknownStyleThrows(): void {
		$this->expectException( \RuntimeException::class );
		$this->formatter->format( $this->sampleCsl(), 'nonsense' );
	}
}
