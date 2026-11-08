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

	/**
	 * A journal article with 22 authors: APA 7 keeps the first 19 + an
	 * ellipsis + the last, Vancouver keeps the first 6 + et al., and the
	 * native serializers list every author. The type must be
	 * `article-journal` for the serial container to render (the source type
	 * map maps "scholarly article" to it).
	 */
	public function testJournalArticleWithManyAuthors(): void {
		$authors = [];
		for ( $i = 1; $i <= 22; $i++ ) {
			$authors[] = [ 'given' => 'G', 'family' => 'Family' . $i ];
		}
		$csl = [
			'type' => 'article-journal',
			'title' => 'Early identification of autism spectrum disorder',
			'author' => $authors,
			'container-title' => 'Pediatrics',
			'volume' => '136',
			'issue' => 'Supplement_1',
			'page' => 'S10-S40',
			'issued' => [ 'date-parts' => [ [ 2015 ] ] ],
			'DOI' => '10.1542/peds.2014-3667c',
		];

		$apa = $this->formatter->format( $csl, 'apa', 'text' );
		$this->assertStringContainsString( 'Family19', $apa );
		$this->assertStringNotContainsString( 'Family20', $apa );
		$this->assertStringContainsString( 'Family22', $apa );
		$this->assertStringContainsString( 'Pediatrics, 136(Supplement_1)', $apa );
		$this->assertStringNotContainsString( 'In Pediatrics', $apa );

		$vancouver = $this->formatter->format( $csl, 'vancouver', 'text' );
		$this->assertStringContainsString( 'Family6', $vancouver );
		$this->assertStringNotContainsString( 'Family7', $vancouver );
		$this->assertStringContainsString( 'et al', $vancouver );

		$bibtex = $this->formatter->format( $csl, 'bibtex', 'text' );
		$this->assertStringContainsString( 'Family22', $bibtex );
		$this->assertStringContainsString( ' and ', $bibtex );

		$ris = $this->formatter->format( $csl, 'ris', 'text' );
		$this->assertStringContainsString( 'AU  - Family22', $ris );
	}

	public function testUnknownStyleThrows(): void {
		$this->expectException( \RuntimeException::class );
		$this->formatter->format( $this->sampleCsl(), 'nonsense' );
	}
}
