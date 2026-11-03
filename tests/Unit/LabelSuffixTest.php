<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Spec\LabelSuffix;
use PHPUnit\Framework\TestCase;

/**
 * The duplicate-label suffix: (2), (3), …; an existing numeric suffix is
 * incremented instead of nested.
 *
 * @license GPL-2.0-or-later
 */
class LabelSuffixTest extends TestCase {

	public function testFirstFreeSuffixIsTwo(): void {
		$this->assertSame( ' (2)', LabelSuffix::nextSuffix( 'Club of Rome', static fn () => false ) );
	}

	public function testTakenTwoFallsThroughToThree(): void {
		$taken = static fn ( string $suffix ): bool => $suffix === ' (2)';
		$this->assertSame( ' (3)', LabelSuffix::nextSuffix( 'Club of Rome', $taken ) );
	}

	public function testExistingSuffixIsIncremented(): void {
		$this->assertSame( ' (3)', LabelSuffix::nextSuffix( 'Club of Rome (2)', static fn () => false ) );
	}

	public function testExistingHigherSuffixIsIncremented(): void {
		$this->assertSame( ' (7)', LabelSuffix::nextSuffix( 'A (6)', static fn () => false ) );
	}

	public function testCallbackSeesEachCandidate(): void {
		$seen = [];
		$suffix = LabelSuffix::nextSuffix(
			'X',
			static function ( string $candidate ) use ( &$seen ): bool {
				$seen[] = $candidate;
				return $candidate === ' (2)' || $candidate === ' (3)';
			}
		);
		$this->assertSame( ' (4)', $suffix );
		$this->assertSame( [ ' (2)', ' (3)', ' (4)' ], $seen );
	}

}
