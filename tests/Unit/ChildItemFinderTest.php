<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Spec\ChildItemFinder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the child-item SPARQL listing (the HTTP execution is
 * MW-bound — ChildItemLookup — and is covered by the dev-stack E2E,
 * mirroring QuotationFinderTest).
 *
 * @license GPL-2.0-or-later
 */
class ChildItemFinderTest extends TestCase {

	private function finderWithCapture( ?array &$captured, ?array $rows ): ChildItemFinder {
		return new ChildItemFinder(
			static function ( string $query ) use ( &$captured, $rows ): ?array {
				$captured = $query;
				return $rows;
			}
		);
	}

	public function testBuildsParentClassFilterQueryAndParsesRows(): void {
		$captured = null;
		$finder = $this->finderWithCapture( $captured, [
			[
				'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q1533' ],
				'label' => [ 'type' => 'literal', 'value' => 'A chapter of the book' ],
			],
			[
				'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q9' ],
			],
			// A row without an item IRI is skipped.
			[ 'label' => [ 'type' => 'literal', 'value' => 'ignored' ] ],
		] );

		$rows = $finder->findForParent(
			'Q1521', 'Q42', 'P8', 'P1',
			'https://wikibase.ronzz.org/entity/',
			'https://wikibase.ronzz.org/prop/direct/'
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( 'Q1533', $rows[0]['qid'] );
		$this->assertSame( 'A chapter of the book', $rows[0]['label'] );
		$this->assertSame( 'Q9', $rows[1]['qid'] );
		$this->assertSame( '', $rows[1]['label'] );

		// The query: part-of parent link + child-class filter + optional label.
		$this->assertStringContainsString(
			'?item wdt:P8 wd:Q1521 .' . "\n" . '  ?item wdt:P1 wd:Q42 .',
			$captured
		);
		$this->assertStringContainsString(
			'OPTIONAL { ?item rdfs:label ?label FILTER(LANG(?label) = "en") }',
			$captured
		);
		$this->assertStringContainsString( 'ORDER BY ?item LIMIT ' . ChildItemFinder::MAX_ROWS, $captured );
		// No LITERAL backslash-n anywhere — the quotation-listing footgun
		// (a single-quoted '\n' reaches Blazegraph as a literal backslash
		// and 400s the query).
		$this->assertStringNotContainsString( '\\', $captured );
	}

	public function testInvalidParentOrMissingPropertiesYieldsEmptyNoQuery(): void {
		$called = false;
		$finder = new ChildItemFinder(
			static function () use ( &$called ): array {
				$called = true;
				return [];
			}
		);
		$rows = $finder->findForParent(
			'not-an-item', 'Q42', 'P8', 'P1', 'e/', 'p/'
		);
		$this->assertSame( [], $rows );
		$this->assertFalse( $called, 'the SPARQL runner must not fire for invalid input' );
	}

	public function testRunnerFailureYieldsNull(): void {
		$finder = new ChildItemFinder(
			static function (): array {
				throw new \RuntimeException( 'WDQS down' );
			}
		);
		$this->assertNull( $finder->findForParent(
			'Q1521', 'Q42', 'P8', 'P1', 'e/', 'p/'
		) );
	}

	public function testRunnerReturningNullYieldsNull(): void {
		$finder = $this->finderWithCapture( $captured, null );
		$this->assertNull( $finder->findForParent(
			'Q1521', 'Q42', 'P8', 'P1', 'e/', 'p/'
		) );
	}

	public function testNoChildrenYieldsEmptyList(): void {
		$finder = $this->finderWithCapture( $captured, [] );
		$this->assertSame( [], $finder->findForParent(
			'Q1521', 'Q42', 'P8', 'P1', 'e/', 'p/'
		) );
	}
}
