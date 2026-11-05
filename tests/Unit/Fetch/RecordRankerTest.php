<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\RecordRanker;
use EmbeddableContent\Fetch\WorkRecord;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Fetch\RecordRanker
 * @license GPL-2.0-or-later
 */
final class RecordRankerTest extends TestCase {

	private function work( string $title ): WorkRecord {
		return new WorkRecord( title: $title );
	}

	private function titles( array $records ): array {
		return array_map( static fn ( WorkRecord $r ): string => $r->title, $records );
	}

	public function testExactTitleBeatsEarlierProviderHits(): void {
		$records = [
			$this->work( 'The predictive power of chronic stress and hassles on the psychological health' ),
			$this->work( 'The use of a caution factor to increase the predictive value' ),
			$this->work( 'A study of the value of a psychological examination' ),
			$this->work( 'On the psychology of prediction.' ),
			$this->work( 'Attribution and the psychology of prediction.' ),
		];

		$ranked = RecordRanker::rank(
			$records,
			'On the psychology of prediction',
			static fn ( WorkRecord $r ): string => $r->title
		);

		$this->assertSame( 'On the psychology of prediction.', $ranked[0]->title );
	}

	public function testPrefixAndSubtitleRankAboveUnrelated(): void {
		$records = [
			$this->work( 'A history of prediction' ),
			$this->work( 'On the psychology of prediction: Whose is the fallacy?' ),
			$this->work( 'Motivation, heuristics, and the psychology of prediction' ),
		];

		$ranked = RecordRanker::rank(
			$records,
			'On the psychology of prediction',
			static fn ( WorkRecord $r ): string => $r->title
		);

		$this->assertSame( 'On the psychology of prediction: Whose is the fallacy?', $ranked[0]->title );
		$this->assertSame( 'Motivation, heuristics, and the psychology of prediction', $ranked[1]->title );
	}

	public function testEqualRelevanceKeepsProviderOrder(): void {
		$records = [ $this->work( 'Alpha' ), $this->work( 'Beta' ) ];
		$ranked = RecordRanker::rank(
			$records,
			'no matching words',
			static fn ( WorkRecord $r ): string => $r->title
		);
		$this->assertSame( [ 'Alpha', 'Beta' ], $this->titles( $ranked ) );
	}

	public function testEmptyQueryLeavesOrderUntouched(): void {
		$records = [ $this->work( 'Beta' ), $this->work( 'Alpha' ) ];
		$this->assertSame( $records, RecordRanker::rank( $records, '   ', static fn ( WorkRecord $r ): string => $r->title ) );
	}

	public function testMarkupAndPunctuationAreIgnored(): void {
		$records = [
			$this->work( 'Something else' ),
			$this->work( '<i>On the psychology of prediction</i>' ),
		];
		$ranked = RecordRanker::rank(
			$records,
			'ON THE PSYCHOLOGY OF PREDICTION',
			static fn ( WorkRecord $r ): string => $r->title
		);
		$this->assertSame( '<i>On the psychology of prediction</i>', $ranked[0]->title );
	}

	public function testNonLatinTokensSurviveNormalisation(): void {
		$this->assertSame( 'école normale', RecordRanker::normalize( 'École  Normale!' ) );
		$this->assertSame( '测试 标题', RecordRanker::normalize( '测试：标题' ) );
	}

}
