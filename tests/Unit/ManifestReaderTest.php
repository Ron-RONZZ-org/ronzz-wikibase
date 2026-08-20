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

	/**
	 * Labels the seed (D2) resolves by exact en-label match — see
	 * seed/seed_instance.py (`find( "instance of" | "equivalent property" |
	 * "equivalent class" | "formatter URL" | "attributed to" | content-type
	 * payload properties)` and the CONTENT_TYPE map) and seed/config_builder.php
	 * (content classes). Dropping any of these breaks the bootstrap, so their
	 * presence is the contract — not a magic row count.
	 */
	private const REQUIRED_PROPERTY_LABELS = [
		'instance of',
		'equivalent property',
		'equivalent class',
		'formatter URL',
		'attributed to',
		'content text',
		'code source',
		'LaTeX source',
	];

	private const REQUIRED_CLASS_LABELS = [
		'quotation content',
		'code snippet',
		'mathematical expression',
		'programming language',
	];

	/** Instance language policy (en/fr/eo) — every manifest row must cover all three. */
	private const INSTANCE_LANGUAGES = [ 'en', 'fr', 'eo' ];

	private ManifestReader $reader;

	protected function setUp(): void {
		$this->reader = new ManifestReader();
	}

	public function testBundledPropertiesManifestParses(): void {
		$rows = $this->reader->readProperties( self::EXT . '/manifests/properties.csv' );
		$this->assertNotEmpty( $rows );

		// The bootstrap contract: labels the seed resolves by exact match.
		foreach ( self::REQUIRED_PROPERTY_LABELS as $label ) {
			$this->assertNotNull(
				$this->findByEnLabel( $rows, $label ),
				"required property label \"$label\" missing from the bundled manifest"
			);
		}

		// Every row must carry the instance's three languages.
		foreach ( $rows as $row ) {
			$this->assertTrilingual( $row, $row->getLabels()['en'] ?? 'row' );
		}

		// `instance of` anchors every entity creation in the seed.
		$instanceOf = $this->findByEnLabel( $rows, 'instance of' );
		$this->assertNotNull( $instanceOf );
		$this->assertSame( 'instance of', $instanceOf->getLabels()['en'] );
		$this->assertSame( 'instance de', $instanceOf->getLabels()['fr'] );
		$this->assertSame( 'ekzemplo de', $instanceOf->getLabels()['eo'] );
		$this->assertSame( 'wikibase-item', $instanceOf->getDatatype() );
		$this->assertSame( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', $instanceOf->getAlignUri() );
		$this->assertSame( 'https://www.wikidata.org/wiki/Property:P31', $instanceOf->getAlignWikidata() );

		// `equivalent property` aligns to wd:P1628 (equivalent property) — NOT
		// P2888 (exact match); regression guard for the D1 data fix.
		$equivalentProperty = $this->findByEnLabel( $rows, 'equivalent property' );
		$this->assertNotNull( $equivalentProperty );
		$this->assertSame(
			'https://www.wikidata.org/wiki/Property:P1628',
			$equivalentProperty->getAlignWikidata()
		);

		// Issue #7: external-id authority properties carry formatter URLs.
		$orcid = $this->findByEnLabel( $rows, 'ORCID' );
		$this->assertNotNull( $orcid );
		$this->assertSame( 'external-id', $orcid->getDatatype() );
		$this->assertSame( 'https://orcid.org/$1', $orcid->getFormatterUrl() );
		$this->assertSame( 'https://www.wikidata.org/wiki/Property:P496', $orcid->getAlignWikidata() );

		// Payload properties carry no alignment.
		$contentText = $this->findByEnLabel( $rows, 'content text' );
		$this->assertNotNull( $contentText );
		$this->assertSame( 'monolingualtext', $contentText->getDatatype() );
		$this->assertNull( $contentText->getAlignUri() );
		$this->assertNull( $contentText->getAlignWikidata() );
	}

	public function testBundledClassesManifestParses(): void {
		$rows = $this->reader->readClasses( self::EXT . '/manifests/classes.csv' );
		$this->assertNotEmpty( $rows );

		foreach ( self::REQUIRED_CLASS_LABELS as $label ) {
			$this->assertNotNull(
				$this->findByEnLabel( $rows, $label ),
				"required class label \"$label\" missing from the bundled manifest"
			);
		}

		foreach ( $rows as $row ) {
			$this->assertTrilingual( $row, $row->getLabels()['en'] ?? 'row' );
		}

		$quotationContent = $this->findByEnLabel( $rows, 'quotation content' );
		$this->assertNotNull( $quotationContent );
		$this->assertSame( 'https://schema.org/Quotation', $quotationContent->getAlignUri() );

		$programmingLanguage = $this->findByEnLabel( $rows, 'programming language' );
		$this->assertNotNull( $programmingLanguage );
		$this->assertSame( 'https://www.wikidata.org/wiki/Q9143', $programmingLanguage->getAlignWikidata() );
	}

	public function testBundledLanguagesManifestParses(): void {
		$rows = $this->reader->readLanguages( self::EXT . '/manifests/languages.csv' );
		$this->assertNotEmpty( $rows );

		foreach ( $rows as $row ) {
			$this->assertTrilingual( $row, $row->getLabels()['en'] ?? $row->getLexer() );
			// Pygments lexer names are lowercase — a case change silently breaks
			// the code-snippet language dropdown contract.
			$this->assertSame(
				strtolower( $row->getLexer() ),
				$row->getLexer(),
				"lexer \"{$row->getLexer()}\" must be lowercase"
			);
		}

		$python = $this->findByLexer( $rows, 'python' );
		$this->assertNotNull( $python );
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

	public function testFormatterUrlOnNonExternalIdThrows(): void {
		$path = $this->writeTemp( "label.en,label.fr,label.eo,description.en,description.fr,description.eo,datatype,align.uri,align.wikidata,formatter.url\n" .
			"foo,foo,foo,desc,desc,desc,string,,,https://example.org/\$1\n" );
		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'formatter URL requires datatype "external-id"' );
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

	private function findByEnLabel( array $rows, string $label ): ?object {
		foreach ( $rows as $row ) {
			if ( $row->getLabels()['en'] === $label ) {
				return $row;
			}
		}
		return null;
	}

	private function findByLexer( array $rows, string $lexer ): ?object {
		foreach ( $rows as $row ) {
			if ( $row->getLexer() === $lexer ) {
				return $row;
			}
		}
		return null;
	}

	private function assertTrilingual( object $row, string $context ): void {
		$missing = array_diff( self::INSTANCE_LANGUAGES, array_keys( $row->getLabels() ) );
		$this->assertSame( [], $missing, "$context: missing manifest languages: " . implode( ',', $missing ) );
	}

	private function writeTemp( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'manifest-' );
		file_put_contents( $path, $contents );
		return $path;
	}
}
