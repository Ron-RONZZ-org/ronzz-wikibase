<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Manifest\ManifestException;
use EmbeddableContent\Manifest\ManifestReader;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure-PHP manifest reader (no MediaWiki/Wikibase runtime).
 *
 * @license GPL-2.0-or-later
 */
class ManifestReaderTest extends TestCase {

	private const EXT = __DIR__ . '/../../extensions/EmbeddableContent';

	private ManifestReader $reader;

	protected function setUp(): void {
		$this->reader = new ManifestReader();
	}

	public function testBundledPropertiesManifestParses(): void {
		$rows = $this->reader->readProperties( self::EXT . '/manifests/properties.csv' );
		$this->assertCount( 11, $rows );

		$instanceOf = $rows[0];
		$this->assertSame( 'instance of', $instanceOf->getLabels()['en'] );
		$this->assertSame( 'instance de', $instanceOf->getLabels()['fr'] );
		$this->assertSame( 'ekzemplo de', $instanceOf->getLabels()['eo'] );
		$this->assertSame( 'wikibase-item', $instanceOf->getDatatype() );
		$this->assertSame( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', $instanceOf->getAlignUri() );
		$this->assertSame( 'https://www.wikidata.org/wiki/Property:P31', $instanceOf->getAlignWikidata() );

		// Payload properties carry no alignment.
		$contentText = $rows[1];
		$this->assertSame( 'monolingualtext', $contentText->getDatatype() );
		$this->assertNull( $contentText->getAlignUri() );
		$this->assertNull( $contentText->getAlignWikidata() );

		// `equivalent property` aligns to wd:P1628 (equivalent property) — NOT
		// P2888 (exact match); regression guard for the D1 data fix.
		$equivalentProperty = $rows[9];
		$this->assertSame( 'equivalent property', $equivalentProperty->getLabels()['en'] );
		$this->assertSame(
			'https://www.wikidata.org/wiki/Property:P1628',
			$equivalentProperty->getAlignWikidata()
		);
	}

	public function testBundledClassesManifestParses(): void {
		$rows = $this->reader->readClasses( self::EXT . '/manifests/classes.csv' );
		$this->assertCount( 4, $rows );

		$this->assertSame( 'quotation content', $rows[0]->getLabels()['en'] );
		$this->assertSame( 'https://schema.org/Quotation', $rows[0]->getAlignUri() );
		$this->assertSame( 'programming language', $rows[3]->getLabels()['en'] );
		$this->assertSame( 'https://www.wikidata.org/wiki/Q9143', $rows[3]->getAlignWikidata() );
	}

	public function testBundledLanguagesManifestParses(): void {
		$rows = $this->reader->readLanguages( self::EXT . '/manifests/languages.csv' );
		$this->assertCount( 80, $rows );

		$python = $rows[array_search( 'python', array_map( static fn ( $row ) => $row->getLexer(), $rows ), true )];
		$this->assertSame( 'Python', $python->getLabels()['en'] );
		$this->assertNull( $python->getWikidataQid() );
	}

	public function testMissingFileThrows(): void {
		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'cannot open manifest' );
		$this->reader->readProperties( '/nonexistent/properties.csv' );
	}

	public function testInvalidDatatypeThrows(): void {
		$path = $this->writeTemp( "label.en,label.fr,label.eo,description.en,description.fr,description.eo,datatype,align.uri,align.wikidata\n" .
			"foo,foo,foo,desc,desc,desc,not-a-datatype,,\n" );
		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'invalid datatype "not-a-datatype"' );
		$this->reader->readProperties( $path );
	}

	public function testMissingLabelThrows(): void {
		$path = $this->writeTemp( "label.en,label.fr,label.eo,description.en,description.fr,description.eo,datatype\n" .
			"foo,,foo,desc,desc,desc,string\n" );
		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'missing label for language "fr"' );
		$this->reader->readProperties( $path );
	}

	public function testLabelDescriptionLanguageMismatchThrows(): void {
		$path = $this->writeTemp( "label.en,label.fr,label.eo,description.en,description.fr,datatype\n" .
			"foo,foo,foo,desc,desc,string\n" );
		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'label and description language columns must match' );
		$this->reader->readProperties( $path );
	}

	public function testDuplicateLabelThrows(): void {
		$path = $this->writeTemp( "label.en,label.fr,label.eo,description.en,description.fr,description.eo,datatype\n" .
			"foo,foo,foo,desc,desc,desc,string\n" .
			"foo,bar,bar,desc,desc,desc,string\n" );
		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'duplicate en label "foo"' );
		$this->reader->readProperties( $path );
	}

	public function testInvalidAlignUrlThrows(): void {
		$path = $this->writeTemp( "label.en,label.fr,label.eo,description.en,description.fr,description.eo,datatype,align.uri\n" .
			"foo,foo,foo,desc,desc,desc,url,not a url\n" );
		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'invalid URL in column "align.uri"' );
		$this->reader->readProperties( $path );
	}

	public function testQuotedCommasInFieldsParse(): void {
		$path = $this->writeTemp( "label.en,label.fr,label.eo,description.en,description.fr,description.eo,datatype\n" .
			"\"a, b\",\"c, d\",\"e, f\",\"g, h\",\"i, j\",\"k, l\",string\n" );
		$rows = $this->reader->readProperties( $path );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'a, b', $rows[0]->getLabels()['en'] );
		$this->assertSame( 'g, h', $rows[0]->getDescriptions()['en'] );
	}

	public function testUtf8BomInFirstHeaderColumnIsStripped(): void {
		$path = $this->writeTemp( "\xEF\xBB\xBFlabel.en,label.fr,label.eo,description.en,description.fr,description.eo,datatype\n" .
			"foo,foo,foo,desc,desc,desc,string\n" );
		$rows = $this->reader->readProperties( $path );
		$this->assertCount( 1, $rows );
	}

	public function testBlankLinesAreSkipped(): void {
		$path = $this->writeTemp( "label.en,label.fr,label.eo,description.en,description.fr,description.eo,datatype\n" .
			"\n" .
			"foo,foo,foo,desc,desc,desc,string\n\n" );
		$rows = $this->reader->readProperties( $path );
		$this->assertCount( 1, $rows );
	}

	public function testWrongColumnCountThrows(): void {
		$path = $this->writeTemp( "label.en,label.fr,label.eo,description.en,description.fr,description.eo,datatype\n" .
			"foo,foo\n" );
		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'expected 7 columns, got 2' );
		$this->reader->readProperties( $path );
	}

	public function testInvalidWikidataQidThrows(): void {
		$path = $this->writeTemp( "lexer,label.en,label.fr,label.eo,description.en,description.fr,description.eo,wikidata_qid\n" .
			"python,Python,Python,Python,programming language,langage de programmation,programlingvo,Q-not-a-number\n" );
		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'invalid Wikidata Q-id "Q-not-a-number"' );
		$this->reader->readLanguages( $path );
	}

	public function testDuplicateLexerThrows(): void {
		$path = $this->writeTemp( "lexer,label.en,label.fr,label.eo,description.en,description.fr,description.eo,wikidata_qid\n" .
			"python,Python,Python,Python,programming language,langage de programmation,programlingvo,\n" .
			"python,Py,Py,Py,programming language,langage de programmation,programlingvo,\n" );
		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'duplicate lexer "python"' );
		$this->reader->readLanguages( $path );
	}

	private function writeTemp( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'manifest-' );
		file_put_contents( $path, $contents );
		return $path;
	}
}
