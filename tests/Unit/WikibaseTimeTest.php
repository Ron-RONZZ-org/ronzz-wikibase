<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Spec\WikibaseTime;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Wikibase TimeValue → `YYYY-MM-DD` normalization (the
 * AddPerson date report: a year-precision date must not prefill as the
 * unwidget-parseable `YYYY-00-00`).
 *
 * @license GPL-2.0-or-later
 */
class WikibaseTimeTest extends TestCase {

	public function testNormalizesYearPrecisionPlaceholders(): void {
		$this->assertSame( '1970-01-01', WikibaseTime::isoDay( '+1970-00-00T00:00:00Z' ) );
	}

	public function testNormalizesMonthPrecisionPlaceholders(): void {
		$this->assertSame( '1970-06-01', WikibaseTime::isoDay( '+1970-06-00T00:00:00Z' ) );
	}

	public function testKeepsADayPrecisionDate(): void {
		$this->assertSame( '1857-06-06', WikibaseTime::isoDay( '+1857-06-06T00:00:00Z' ) );
	}

	public function testRejectsANonDateString(): void {
		$this->assertNull( WikibaseTime::isoDay( 'nonsense' ) );
	}

}
