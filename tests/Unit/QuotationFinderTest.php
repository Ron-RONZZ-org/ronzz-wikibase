<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Spec\QuotationFinder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the source-quotation SPARQL listing (the HTTP execution
 * is MW-bound — QuotationLookup — and is covered by the dev-stack E2E,
 * like DuplicateFinderTest).
 *
 * @license GPL-2.0-or-later
 */
class QuotationFinderTest extends TestCase {

	private function finderWithCapture( ?array &$captured, ?array $rows ): QuotationFinder {
		return new QuotationFinder(
			static function ( string $query ) use ( &$captured, $rows ): ?array {
				$captured = $query;
				return $rows;
			}
		);
	}

	public function testBuildsSourceClassFilterQueryAndParsesRows(): void {
		$captured = null;
		$finder = $this->finderWithCapture( $captured, [
			[
				'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q1402' ],
				'content' => [ 'type' => 'literal', 'xml:lang' => 'en', 'value' => "First line.\\nSecond line." ],
				'label' => [ 'type' => 'literal', 'value' => 'A quotation' ],
			],
			[
				'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q9' ],
				'label' => [ 'type' => 'literal', 'value' => 'No content' ],
			],
			// A row without an item IRI is skipped.
			[ 'label' => [ 'type' => 'literal', 'value' => 'ignored' ] ],
		] );

		$rows = $finder->findForSource(
			'Q1521', 'Q42', 'P7', 'P8', 'P1',
			'https://wikibase.ronzz.org/entity/',
			'https://wikibase.ronzz.org/prop/direct/'
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( 'Q1402', $rows[0]['qid'] );
		// The payload is returned DECODED (the escape-at-rest \n becomes a
		// real newline) — the listing shows the logical quotation text.
		$this->assertSame( "First line.\nSecond line.", $rows[0]['content'] );
		$this->assertSame( 'A quotation', $rows[0]['label'] );
		$this->assertSame( 'Q9', $rows[1]['qid'] );
		$this->assertSame( '', $rows[1]['content'] );

		// The query: source link + quotation class + optional payload.
		$this->assertStringContainsString(
			'?item wdt:P8 wd:Q1521 .' . "\n" . '  ?item wdt:P1 wd:Q42 .',
			$captured
		);
		$this->assertStringContainsString(
			'OPTIONAL { ?item wdt:P7 ?content }',
			$captured
		);
		$this->assertStringContainsString( 'ORDER BY ?item LIMIT ' . QuotationFinder::MAX_ROWS, $captured );
	}

	public function testInvalidSourceOrMissingPropertiesYieldsEmptyNoQuery(): void {
		$called = false;
		$finder = new QuotationFinder(
			static function () use ( &$called ): array {
				$called = true;
				return [];
			}
		);
		$rows = $finder->findForSource(
			'not-an-item', 'Q42', 'P7', 'P8', 'P1', 'e/', 'p/'
		);
		$this->assertSame( [], $rows );
		$this->assertFalse( $called, 'the SPARQL runner must not fire for invalid input' );
	}

	public function testRunnerFailureYieldsNull(): void {
		$finder = new QuotationFinder(
			static function (): array {
				throw new \RuntimeException( 'WDQS down' );
			}
		);
		$this->assertNull( $finder->findForSource(
			'Q1521', 'Q42', 'P7', 'P8', 'P1', 'e/', 'p/'
		) );
	}

	public function testRunnerReturningNullYieldsNull(): void {
		$finder = $this->finderWithCapture( $captured, null );
		$this->assertNull( $finder->findForSource(
			'Q1521', 'Q42', 'P7', 'P8', 'P1', 'e/', 'p/'
		) );
	}

	public function testNoQuotationsYieldsEmptyList(): void {
		$finder = $this->finderWithCapture( $captured, [] );
		$this->assertSame( [], $finder->findForSource(
			'Q1521', 'Q42', 'P7', 'P8', 'P1', 'e/', 'p/'
		) );
	}
}
