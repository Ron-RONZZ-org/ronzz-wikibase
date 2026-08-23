<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\HtmlMetadataParser;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class HtmlMetadataParserTest extends TestCase {

	public function testExtractsStandardHeadMarkers(): void {
		$html = '<html><head>'
			. '<meta property="og:title" content="Example &amp; Co">'
			. '<meta name="description" content="A   short  description.">'
			. '<meta name="keywords" content="one, two, three">'
			. '<title>Fallback Title</title>'
			. '</head><body>'
			. '<p>The   first   paragraph   text.</p>'
			. '<p>Second paragraph.</p>'
			. '</body></html>';

		$meta = HtmlMetadataParser::extract( $html );

		$this->assertSame( 'Example & Co', $meta->title ); // og:title wins over <title>, entities decoded
		$this->assertSame( 'A short description.', $meta->description ); // whitespace collapsed
		$this->assertSame( 'one, two, three', $meta->keywords );
		$this->assertSame( 'The first paragraph text.', $meta->intro );
	}

	public function testMetaAttributeOrderIsIrrelevant(): void {
		$html = '<meta content="Ordered differently" property="og:description">';
		$this->assertSame( 'Ordered differently', HtmlMetadataParser::extract( $html )->description );
	}

	public function testTitleFallsBackToTitleTag(): void {
		$html = '<title>Plain Title</title><body><p>Intro here.</p></body>';
		$meta = HtmlMetadataParser::extract( $html );
		$this->assertSame( 'Plain Title', $meta->title );
		$this->assertSame( 'Intro here.', $meta->intro );
	}

	public function testUnrelatedMetaIsIgnored(): void {
		$html = '<meta name="robots" content="noindex"><meta charset="utf-8">';
		$this->assertTrue( HtmlMetadataParser::extract( $html )->isEmpty() );
	}

	public function testLengthCaps(): void {
		$long = str_repeat( 'x', 3000 );
		$html = '<title>' . $long . '</title>';
		$this->assertSame( HtmlMetadataParser::MAX_TITLE, mb_strlen( HtmlMetadataParser::extract( $html )->title ) );
	}
}
