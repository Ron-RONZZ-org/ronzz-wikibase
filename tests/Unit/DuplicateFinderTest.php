<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\EntityLabelMatcher;
use EmbeddableContent\Spec\DuplicateFinder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the duplication-guard SPARQL matching (the label-match
 * integration is MW-bound — the entity lookup — and is covered by the
 * dev-stack E2E, like EntityLabelMatcher::findBestMatch).
 *
 * @license GPL-2.0-or-later
 */
class DuplicateFinderTest extends TestCase {

	public function testFindByValuesBuildsValuesQueryAndParsesRows(): void {
		$captured = null;
		$finder = new DuplicateFinder(
			static function ( string $query ) use ( &$captured ): array {
				$captured = $query;
				return [ [
					'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q1402' ],
					'label' => [ 'type' => 'literal', 'value' => 'Moby Project' ],
				] ];
			}
		);
		$match = $finder->findByValues(
			[ 'P12' => 'Q28771536', 'P37' => 'https://github.com/moby/moby' ],
			'https://wikibase.ronzz.org/entity/',
			'https://wikibase.ronzz.org/prop/direct/'
		);
		$this->assertSame( 'Q1402', $match['itemId'] );
		$this->assertSame( 'Moby Project', $match['label'] );
		// URL/string values emit BOTH the literal and the IRI form (URL
		// statement values are RDF URIs, external ids are literals).
		$this->assertStringContainsString(
			'VALUES (?p ?v) { (wdt:P12 "Q28771536") (wdt:P12 <Q28771536>)'
			. ' (wdt:P37 "https://github.com/moby/moby") (wdt:P37 <https://github.com/moby/moby>) }',
			$captured
		);
		$this->assertStringContainsString( '?item ?p ?v', $captured );
	}

	public function testFindByValuesEscapesQuotesAndBackslashes(): void {
		$captured = null;
		$finder = new DuplicateFinder(
			static function ( string $query ) use ( &$captured ): array {
				$captured = $query;
				return [];
			}
		);
		$finder->findByValues( [ 'P55' => 'a"b\\c' ], 'e/', 'p/' );
		// Literal form: " and \ escaped. IRI form: \ escaped (">" would be);
		// a quote is legal inside an IRIREF.
		$this->assertStringContainsString( '(wdt:P55 "a\\"b\\\\c") (wdt:P55 <a"b\\\\c>)', $captured );
	}

	public function testFindByValuesSkipsEmptyAndInvalidPairs(): void {
		$called = false;
		$finder = new DuplicateFinder(
			static function () use ( &$called ): array {
				$called = true;
				return [];
			}
		);
		$this->assertNull( $finder->findByValues( [], 'e/', 'p/' ) );
		$this->assertNull( $finder->findByValues( [ 'P12' => '', 'P12' => '  ' ], 'e/', 'p/' ) );
		$this->assertNull( $finder->findByValues( [ 'not-a-property' => 'x' ], 'e/', 'p/' ) );
		$this->assertFalse( $called, 'the SPARQL runner must not fire for empty pair sets' );
	}

	public function testFindByValuesRunnerFailureYieldsNull(): void {
		$finder = new DuplicateFinder(
			static function (): array {
				throw new \RuntimeException( 'WDQS down' );
			}
		);
		$this->assertNull( $finder->findByValues( [ 'P12' => 'Q1' ], 'e/', 'p/' ) );
	}

	public function testFirstItemFromRowsSkipsNonItemUrisAndFallsBackToQidLabel(): void {
		$rows = [
			[ 'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Property:P12' ] ],
			[ 'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q99' ] ],
		];
		$match = DuplicateFinder::firstItemFromRows( $rows );
		$this->assertSame( 'Q99', $match['itemId'] );
		$this->assertSame( 'Q99', $match['label'], 'label falls back to the id' );
	}

	public function testFirstItemFromRowsEmpty(): void {
		$this->assertNull( DuplicateFinder::firstItemFromRows( [] ) );
	}

	public function testFindByLabelReturnsNullForBlankLabel(): void {
		$finder = new DuplicateFinder( null, new EntityLabelMatcher( static fn () => [] ) );
		$this->assertNull( $finder->findByLabel( '   ' ) );
		$this->assertNull( $finder->findByLabel( '' ) );
	}

}
