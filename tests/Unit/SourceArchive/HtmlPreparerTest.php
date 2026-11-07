<?php

declare( strict_types = 1 );

namespace Tests\Unit\SourceArchive;

use EmbeddableContent\SourceArchive\HtmlPreparer;
use PHPUnit\Framework\TestCase;

/**
 * HTML preparation before the Pandoc conversion (pure, no MediaWiki).
 *
 * Chrome blocks are dropped; every <img> becomes a placeholder token and its
 * src/alt are recorded for the media-note restoration.
 *
 * @license GPL-2.0-or-later
 */
class HtmlPreparerTest extends TestCase {

	public function testStripsScriptsAndRecordsImages(): void {
		$prepared = ( new HtmlPreparer() )->prepare(
			'<p>hi</p><script>alert(1)</script><img src="a.png" alt="An alt">'
		);

		$this->assertStringNotContainsString( '<script', $prepared['html'] );
		$this->assertStringContainsString( sprintf( HtmlPreparer::IMAGE_TOKEN, 0 ), $prepared['html'] );
		$this->assertSame(
			[ [ 'src' => 'a.png', 'alt' => 'An alt' ] ],
			$prepared['images']
		);
	}

	public function testDropsChromeBlocks(): void {
		$prepared = ( new HtmlPreparer() )->prepare(
			'<nav>menu</nav><article>body</article><footer>f</footer>'
		);

		$this->assertSame( '<article>body</article>', $prepared['html'] );
	}

	public function testDecodesEntitiesInAttributes(): void {
		$prepared = ( new HtmlPreparer() )->prepare(
			'<img src="x.png" alt="A &amp; B">'
		);

		$this->assertSame( 'A & B', $prepared['images'][0]['alt'] );
	}

	public function testSingleQuotedAndUnquotedAttributes(): void {
		$preparer = new HtmlPreparer();

		$single = $preparer->prepare( "<img src='x.png' alt='single'>" );
		$this->assertSame( 'x.png', $single['images'][0]['src'] );
		$this->assertSame( 'single', $single['images'][0]['alt'] );

		$bare = $preparer->prepare( '<img src=x.png alt=bare>' );
		$this->assertSame( 'x.png', $bare['images'][0]['src'] );
		$this->assertSame( 'bare', $bare['images'][0]['alt'] );
	}

	public function testRefBlocksArePlaceholdered(): void {
		$prepared = ( new HtmlPreparer() )->prepare(
			'<p>claim<ref name="a">source</ref> more<ref name="b" /></p>'
		);

		$this->assertStringContainsString( sprintf( HtmlPreparer::REF_TOKEN, 0 ), $prepared['html'] );
		$this->assertStringContainsString( sprintf( HtmlPreparer::REF_TOKEN, 1 ), $prepared['html'] );
		$this->assertSame(
			[ '<ref name="a">source</ref>', '<ref name="b" />' ],
			$prepared['refs']
		);
	}
}
