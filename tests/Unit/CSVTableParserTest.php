<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use MediaWiki\Extension\CSVTable\CSVTableException;
use MediaWiki\Extension\CSVTable\CSVTableParser;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../extensions/CSVTable/src/CSVTableException.php';
require_once __DIR__ . '/../../extensions/CSVTable/src/CSVTableParser.php';

/**
 * Pure-PHP tests for the CSVTable parser (the media handler's CSV → rows
 * step). The output escaping is covered by tests/e2e/run_csv_e2e.py.
 *
 * @license GPL-2.0-or-later
 */
final class CSVTableParserTest extends TestCase {

	public function testParsesCommaCsvWithHeader(): void {
		$parsed = CSVTableParser::parse( "name,age\nAlice,30\nBob,25\n" );
		$this->assertTrue( $parsed['header'] );
		$this->assertSame(
			[ [ 'name', 'age' ], [ 'Alice', '30' ], [ 'Bob', '25' ] ],
			$parsed['rows']
		);
	}

	public function testFirstRowHeaderCanBeDisabled(): void {
		$parsed = CSVTableParser::parse( "name,age\nAlice,30", ',', false );
		$this->assertFalse( $parsed['header'] );
		$this->assertSame( [ [ 'name', 'age' ], [ 'Alice', '30' ] ], $parsed['rows'] );
	}

	public function testAutoDetectsSemicolon(): void {
		$parsed = CSVTableParser::parse( "a;b;c\n1;2;3" );
		$this->assertSame( [ [ 'a', 'b', 'c' ], [ '1', '2', '3' ] ], $parsed['rows'] );
	}

	public function testAutoDetectsTab(): void {
		$parsed = CSVTableParser::parse( "a\tb\n1\t2" );
		$this->assertSame( [ [ 'a', 'b' ], [ '1', '2' ] ], $parsed['rows'] );
	}

	public function testExplicitDelimiterOverridesAutoDetection(): void {
		// The header contains commas, but the configured delimiter is ';'.
		$parsed = CSVTableParser::parse( "a,b;c\n1,2;3", ';' );
		$this->assertSame( [ [ 'a,b', 'c' ], [ '1,2', '3' ] ], $parsed['rows'] );
	}

	public function testQuotedFieldsWithEmbeddedDelimiterAndNewline(): void {
		$parsed = CSVTableParser::parse( "id,note\n1,\"a, b\"\n2,\"line1\nline2\"" );
		$this->assertSame( [ 'id', 'note' ], $parsed['rows'][0] );
		$this->assertSame( [ '1', 'a, b' ], $parsed['rows'][1] );
		$this->assertSame( [ '2', "line1\nline2" ], $parsed['rows'][2] );
	}

	public function testStripsUtf8Bom(): void {
		$parsed = CSVTableParser::parse( "\xEF\xBB\xBFname,age\nAlice,30" );
		$this->assertSame( [ 'name', 'age' ], $parsed['rows'][0] );
	}

	public function testSkipsBlankLines(): void {
		$parsed = CSVTableParser::parse( "a,b\n\n1,2\n" );
		$this->assertSame( [ [ 'a', 'b' ], [ '1', '2' ] ], $parsed['rows'] );
	}

	public function testEmptyDataYieldsNoRows(): void {
		$parsed = CSVTableParser::parse( '' );
		$this->assertSame( [], $parsed['rows'] );
		$this->assertFalse( $parsed['header'] );
	}

	public function testRowCapBreachThrows(): void {
		$this->expectException( CSVTableException::class );
		$this->expectExceptionMessage( 'too-many-rows' );
		CSVTableParser::parse( "a\n1\n2\n3", ',', true, 2 );
	}

	public function testColumnCapBreachThrows(): void {
		$this->expectException( CSVTableException::class );
		$this->expectExceptionMessage( 'too-many-columns' );
		CSVTableParser::parse( "a,b,c,d", ',', true, 500, 3 );
	}
}
