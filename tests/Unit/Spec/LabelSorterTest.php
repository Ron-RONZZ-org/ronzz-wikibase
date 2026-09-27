<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\LabelSorter;
use PHPUnit\Framework\TestCase;

/**
 * LabelSorter — locale-aware alphabetical sorting of HTMLForm option maps
 * (`[label => value]`), used by the AddSource / AddCollective pickers
 * (2026-09 UX batch).
 *
 * @license GPL-2.0-or-later
 */
final class LabelSorterTest extends TestCase {

	public function testSortsByLabelAndKeepsValues(): void {
		$input = [
			'Website' => 'Q10',
			'Book' => 'Q8',
			'Film' => 'Q12',
			'Book excerpt' => 'Q21',
		];
		$this->assertSame(
			[
				'Book' => 'Q8',
				'Book excerpt' => 'Q21',
				'Film' => 'Q12',
				'Website' => 'Q10',
			],
			LabelSorter::sortByLabel( $input, 'en' )
		);
	}

	public function testSingleOptionIsUnchanged(): void {
		$this->assertSame( [ 'FOSS' => 'Q1' ], LabelSorter::sortByLabel( [ 'FOSS' => 'Q1' ], 'en' ) );
	}

	public function testEmptyIsUnchanged(): void {
		$this->assertSame( [], LabelSorter::sortByLabel( [], 'en' ) );
	}

	/**
	 * A byte sort puts "Livre" before "Législation" ('i' < 'é' bytes); the
	 * French collation must place "Législation" first (é sorts as e).
	 */
	public function testFrenchAccentsCollateLikeTheBaseLetter(): void {
		$input = [
			'Livre' => 'Q1',
			'Législation' => 'Q2',
			'Présentation' => 'Q3',
			'Thèse' => 'Q4',
		];
		$this->assertSame(
			[ 'Législation', 'Livre', 'Présentation', 'Thèse' ],
			array_keys( LabelSorter::sortByLabel( $input, 'fr' ) )
		);
	}

	public function testCaseInsensitiveOrder(): void {
		$input = [ 'website' => 'Q1', 'Book' => 'Q2', 'apple' => 'Q3' ];
		$this->assertSame(
			[ 'apple', 'Book', 'website' ],
			array_keys( LabelSorter::sortByLabel( $input, 'en' ) )
		);
	}
}
