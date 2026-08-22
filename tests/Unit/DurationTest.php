<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Duration;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class DurationTest extends TestCase {

	/** @return iterable<string,array{string,?int}> */
	public static function parseProvider(): iterable {
		yield 'empty' => [ '', 0 ];
		yield 'whitespace' => [ '   ', 0 ];
		yield 'mm-ss' => [ '3:45', 225 ];
		yield 'mm-ss-zero-padded' => [ '03:05', 185 ];
		yield 'h-mm-ss' => [ '1:02:30', 3750 ];
		yield 'hh-mm-ss' => [ '10:00:00', 36000 ];
		yield 'seconds-only-zero' => [ '0:00', 0 ];
		yield 'mm-59' => [ '59:59', 3599 ];
		yield 'single-digit-seconds-zero-padded' => [ '1:05', 65 ];
	}

	/** @dataProvider parseProvider */
	public function testParseSeconds( string $input, ?int $expected ): void {
		$this->assertSame( $expected, Duration::parseSeconds( $input ) );
	}

	/** @return iterable<string,array{string}> */
	public static function invalidProvider(): iterable {
		yield 'minutes-over-59' => [ '60:00' ];
		yield 'seconds-over-59' => [ '1:60' ];
		yield 'negative' => [ '-1:00' ];
		yield 'colonless' => [ '345' ];
		yield 'decimal' => [ '3.5' ];
		yield 'freeform' => [ 'about three minutes' ];
	}

	/** @dataProvider invalidProvider */
	public function testParseSecondsRejectsMalformed( string $input ): void {
		$this->assertNull( Duration::parseSeconds( $input ) );
	}

	/** @return iterable<string,array{int,string}> */
	public static function formatProvider(): iterable {
		yield 'sub-minute' => [ 65, '01:05' ];
		yield 'minutes' => [ 225, '03:45' ];
		yield 'hour' => [ 3750, '1:02:30' ];
		yield 'zero' => [ 0, '00:00' ];
		yield 'round-trip' => [ 36000, '10:00:00' ];
	}

	/** @dataProvider formatProvider */
	public function testFormatSeconds( int $seconds, string $expected ): void {
		$this->assertSame( $expected, Duration::formatSeconds( $seconds ) );
	}

	/** @dataProvider parseProvider */
	public function testRoundTrip( string $input, ?int $seconds ): void {
		if ( $seconds === null ) {
			$this->addToAssertionCount( 1 );
			return;
		}
		$this->assertSame( $seconds, Duration::parseSeconds( Duration::formatSeconds( $seconds ) ) );
	}
}
