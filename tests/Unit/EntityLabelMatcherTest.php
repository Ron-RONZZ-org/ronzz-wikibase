<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\EntityLabelMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Pure scoring of the fuzzy entity-label matcher (the term-store search +
 * entity lookup in findBestMatch are MW-bound and covered by the dev-stack /
 * CI page-flow E2E, per the repo's testing conventions).
 *
 * @covers \EmbeddableContent\EntityLabelMatcher
 * @license GPL-2.0-or-later
 */
final class EntityLabelMatcherTest extends TestCase {

	public function testExactMatchScoresOne(): void {
		$this->assertSame( 1.0, EntityLabelMatcher::scorePair( 'CC BY-SA 4.0', 'CC BY-SA 4.0' ) );
	}

	public function testCasePunctuationParentheticalInsensitiveExact(): void {
		// Lowercasing, punctuation stripping and a trailing parenthetical
		// qualifier must not block an exact match.
		$this->assertSame( 1.0, EntityLabelMatcher::scorePair( 'Nature', 'Nature (journal)' ) );
		$this->assertSame( 1.0, EntityLabelMatcher::scorePair( 'mit license', 'MIT License' ) );
	}

	public function testPrefixMatch(): void {
		$this->assertGreaterThanOrEqual(
			EntityLabelMatcher::GOOD_MATCH_THRESHOLD,
			EntityLabelMatcher::scorePair( 'Nature Publishing Group', 'Nature' )
		);
		// Short labels must NOT prefix-match (too risky: "The" ~ "Theory").
		$this->assertLessThan(
			EntityLabelMatcher::GOOD_MATCH_THRESHOLD,
			EntityLabelMatcher::scorePair( 'The', 'Theory of Everything' )
		);
	}

	public function testTokenContainment(): void {
		$this->assertGreaterThanOrEqual(
			EntityLabelMatcher::GOOD_MATCH_THRESHOLD,
			EntityLabelMatcher::scorePair( 'CC BY-SA 4.0 International', 'CC BY-SA 4.0' )
		);
	}

	public function testLevenshteinNearMiss(): void {
		// One-character difference must still be a good match.
		$this->assertGreaterThanOrEqual(
			EntityLabelMatcher::GOOD_MATCH_THRESHOLD,
			EntityLabelMatcher::scorePair( 'MIT License', 'MIT Licence' )
		);
	}

	public function testUnrelatedLabelsScoreZero(): void {
		$this->assertSame( 0.0, EntityLabelMatcher::scorePair( 'Flameshot', 'Creative Commons' ) );
		$this->assertLessThan(
			EntityLabelMatcher::GOOD_MATCH_THRESHOLD,
			EntityLabelMatcher::scorePair( 'Cambridge University Press', 'Cambridge City Council' )
		);
	}

	public function testEmptyInputsNeverMatch(): void {
		$this->assertSame( 0.0, EntityLabelMatcher::scorePair( '', 'Anything' ) );
		$this->assertSame( 0.0, EntityLabelMatcher::scorePair( 'Anything', '' ) );
		$this->assertNull( EntityLabelMatcher::bestMatchFromLabels( '', [ 'A' => 'Q1' ] ) );
	}

	public function testBestMatchFromLabelsPicksBestAboveThreshold(): void {
		$labels = [
			'CC BY 4.0' => 'Q100',
			'Public domain' => 'Q200',
			'Flameshot' => 'Q300',
		];
		$best = EntityLabelMatcher::bestMatchFromLabels( 'CC BY-SA 4.0 International', $labels );
		$this->assertNotNull( $best );
		// The CC BY 4.0 item wins on token containment, not the unrelated ones.
		$this->assertSame( 'CC BY 4.0', $best['label'] );
	}

	public function testBestMatchFromLabelsNullWhenNothingMatches(): void {
		$this->assertNull(
			EntityLabelMatcher::bestMatchFromLabels( 'Totally Unknown Thing', [ 'CC BY 4.0' => 'Q100' ] )
		);
	}

	public function testUnicodeLabels(): void {
		$this->assertSame( 1.0, EntityLabelMatcher::scorePair( 'École Normale Supérieure', 'École normale supérieure' ) );
	}
}
