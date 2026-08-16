<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WikibaseCitation\Manifest\CitationMapException;
use WikibaseCitation\Manifest\CitationMapReader;

/**
 * Unit tests for the pure-PHP citation map reader (no MediaWiki/Wikibase runtime).
 *
 * @license GPL-2.0-or-later
 */
class CitationMapReaderTest extends TestCase {

	private const EXT = __DIR__ . '/../../extensions/WikibaseCitation';

	private CitationMapReader $reader;

	protected function setUp(): void {
		$this->reader = new CitationMapReader();
	}

	public function testBundledPropertyMapParses(): void {
		$map = $this->reader->readPropertyMap( self::EXT . '/manifests/citation-property-map.json' );
		$this->assertSame(
			[ 'author', 'container-title', 'issued', 'URL' ],
			array_keys( $map )
		);
		$this->assertSame( 'attributed to', $map['author'] );
		$this->assertSame( 'source', $map['container-title'] );
		$this->assertSame( 'date', $map['issued'] );
		$this->assertSame( 'source URL', $map['URL'] );
	}

	public function testBundledTypeMapParses(): void {
		$map = $this->reader->readTypeMap( self::EXT . '/manifests/citation-type-map.json' );
		$this->assertSame(
			[ 'quotation content', 'code snippet', 'mathematical expression', 'programming language' ],
			array_keys( $map )
		);
		$this->assertSame( 'article', $map['quotation content'] );
		$this->assertSame( 'software', $map['code snippet'] );
		$this->assertSame( 'document', $map['mathematical expression'] );
		$this->assertSame( 'entry-encyclopedia', $map['programming language'] );
	}

	public function testUnknownCslFieldThrows(): void {
		$path = $this->writeTemp( '{ "author": "attributed to", "not-a-csl-field": "source" }' );
		$this->expectException( CitationMapException::class );
		$this->expectExceptionMessage( 'unknown CSL field "not-a-csl-field"' );
		$this->reader->readPropertyMap( $path );
	}

	public function testUnknownCslTypeThrows(): void {
		$path = $this->writeTemp( '{ "quotation content": "not-a-csl-type" }' );
		$this->expectException( CitationMapException::class );
		$this->expectExceptionMessage( 'unknown CSL type "not-a-csl-type"' );
		$this->reader->readTypeMap( $path );
	}

	public function testEmptyPropertyLabelThrows(): void {
		$path = $this->writeTemp( '{ "author": "" }' );
		$this->expectException( CitationMapException::class );
		$this->expectExceptionMessage( 'empty property label for CSL field "author"' );
		$this->reader->readPropertyMap( $path );
	}

	public function testEmptyClassLabelThrows(): void {
		$path = $this->writeTemp( '{ "": "article" }' );
		$this->expectException( CitationMapException::class );
		$this->expectExceptionMessage( 'empty class label' );
		$this->reader->readTypeMap( $path );
	}

	public function testNonObjectJsonThrows(): void {
		$path = $this->writeTemp( '[ "not", "an", "object" ]' );
		$this->expectException( CitationMapException::class );
		$this->expectExceptionMessage( 'not a JSON object' );
		$this->reader->readPropertyMap( $path );
	}

	public function testMissingFileThrows(): void {
		$this->expectException( CitationMapException::class );
		$this->expectExceptionMessage( 'cannot open manifest' );
		$this->reader->readPropertyMap( '/nonexistent/citation-property-map.json' );
	}

	public function testNonStringValueThrows(): void {
		$path = $this->writeTemp( '{ "author": 42 }' );
		$this->expectException( CitationMapException::class );
		$this->expectExceptionMessage( 'must be string => string pairs' );
		$this->reader->readPropertyMap( $path );
	}

	private function writeTemp( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'citation-map-' );
		file_put_contents( $path, $contents );
		return $path;
	}
}
