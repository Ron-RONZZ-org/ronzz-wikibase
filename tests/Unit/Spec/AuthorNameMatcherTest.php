<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\AuthorNameMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Author-name → person-label scoring for the AddSource author resolver.
 *
 * @covers \EmbeddableContent\Spec\AuthorNameMatcher
 * @license GPL-2.0-or-later
 */
class AuthorNameMatcherTest extends TestCase {

	/** @return array{score:float,reason:string} */
	private static function score( string $given, string $family, string $label ): array {
		return AuthorNameMatcher::score(
			[ 'givenName' => $given, 'familyName' => $family ],
			$label
		);
	}

	public function testExactFullName(): void {
		$result = self::score( 'John', 'Smith', 'John Smith' );
		$this->assertSame( 1.0, $result['score'] );
		$this->assertSame( 'exact', $result['reason'] );
	}

	public function testNoMiddleNameAgainstMiddleName(): void {
		$result = self::score( 'John', 'Smith', 'John Paul Smith' );
		$this->assertSame( 0.95, $result['score'] );
		$this->assertSame( 'given_prefix', $result['reason'] );
	}

	public function testInitialAgainstFullGivenName(): void {
		$result = self::score( 'J.', 'Smith', 'John Smith' );
		$this->assertSame( 0.9, $result['score'] );
		$this->assertSame( 'given_initial', $result['reason'] );
	}

	public function testFuzzyGivenName(): void {
		$result = self::score( 'Jon', 'Smith', 'John Smith' );
		$this->assertSame( 0.7, $result['score'] );
		$this->assertSame( 'family_only', $result['reason'] );
	}

	public function testSameFamilyDifferentGivenNameIsNotACandidate(): void {
		$result = self::score( 'John', 'Smith', 'Jane Smith' );
		$this->assertSame( 0.7, $result['score'] );
	}

	public function testDifferentFamilyScoresZeroUnlessLabelSimilar(): void {
		$result = self::score( 'John', 'Smith', 'Alice Jones' );
		$this->assertSame( 0.0, $result['score'] );
	}

	public function testMononymMatchesFamilyOnly(): void {
		$result = self::score( '', 'Plato', 'Plato' );
		$this->assertSame( 1.0, $result['score'] );
		$this->assertSame( 'exact', $result['reason'] );

		// A disambiguated label falls back to the generic label similarity
		// (the parenthetical makes the label's last-word "family" unusable).
		$result = self::score( '', 'Plato', 'Plato (philosopher)' );
		$this->assertGreaterThanOrEqual( 0.8, $result['score'] );
		$this->assertContains( $result['reason'], [ 'mononym', 'fuzzy' ] );
	}

	public function testCommaReversedLabelIsMatched(): void {
		$result = self::score( 'Christian', 'Moro', 'Moro, Christian' );
		$this->assertSame( 0.85, $result['score'] );
		$this->assertSame( 'fuzzy', $result['reason'] );
	}

	public function testEmptyCandidate(): void {
		$result = self::score( 'John', 'Smith', '   ' );
		$this->assertSame( 0.0, $result['score'] );
		$this->assertSame( 'empty', $result['reason'] );
	}
}
