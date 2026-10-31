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

	public function testBceTimeKeepsTheSign(): void {
		$this->assertSame( '-0384-01-01', WikibaseTime::isoDay( '-0384-00-00T00:00:00Z' ) );
		$this->assertSame( '-0384-01-01', WikibaseTime::isoDay( '-0384-01-01T00:00:00Z' ) );
	}

	public function testSignedDayValidation(): void {
		$this->assertTrue( WikibaseTime::isSignedDay( '1970-01-01' ) );
		$this->assertTrue( WikibaseTime::isSignedDay( '-0384-01-01' ) );
		$this->assertFalse( WikibaseTime::isSignedDay( '-0000-01-01' ) );
		$this->assertFalse( WikibaseTime::isSignedDay( '0384-13-01' ) );
		$this->assertFalse( WikibaseTime::isSignedDay( '0384-00-01' ) );
		$this->assertFalse( WikibaseTime::isSignedDay( 'nonsense' ) );
	}

	public function testPartsParsesTheSignAndDate(): void {
		$this->assertSame(
			[ 'negative' => true, 'year' => 384, 'month' => 1, 'day' => 1 ],
			WikibaseTime::parts( '-0384-01-01' )
		);
		$this->assertSame(
			[ 'negative' => false, 'year' => 1970, 'month' => 12, 'day' => 31 ],
			WikibaseTime::parts( '1970-12-31' )
		);
	}

}
