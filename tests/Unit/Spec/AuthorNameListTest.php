<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\AuthorNameList;
use PHPUnit\Framework\TestCase;

/**
 * Author-name string parsing for the AddSource "Search for authors" flow.
 *
 * @covers \EmbeddableContent\Spec\AuthorNameList
 * @license GPL-2.0-or-later
 */
class AuthorNameListTest extends TestCase {

	/** @return list<array{givenName:string,familyName:string}> */
	private static function names( array $result ): array {
		return array_map(
			static fn ( array $name ): array => [
				'givenName' => $name['givenName'],
				'familyName' => $name['familyName'],
			],
			$result['names']
		);
	}

	public function testCommaSeparatedGivenFirst(): void {
		$result = AuthorNameList::parse( 'Christian Moro, Charlotte Phelps, Vineesha Veer' );
		$this->assertSame( [
			[ 'givenName' => 'Christian', 'familyName' => 'Moro' ],
			[ 'givenName' => 'Charlotte', 'familyName' => 'Phelps' ],
			[ 'givenName' => 'Vineesha', 'familyName' => 'Veer' ],
		], self::names( $result ) );
		$this->assertSame( [], $result['warnings'] );
	}

	public function testSemicolonSeparatedFamilyFirst(): void {
		$result = AuthorNameList::parse( 'Moro, Christian; Phelps, Charlotte' );
		$this->assertSame( [
			[ 'givenName' => 'Christian', 'familyName' => 'Moro' ],
			[ 'givenName' => 'Charlotte', 'familyName' => 'Phelps' ],
		], self::names( $result ) );
		$this->assertSame( 'Moro, Christian', $result['names'][0]['original'] );
		$this->assertTrue( $result['names'][0]['familyFirst'] );
	}

	public function testSemicolonSeparatedGivenFirst(): void {
		$result = AuthorNameList::parse( 'Christian Moro; Charlotte Phelps' );
		$this->assertSame( [
			[ 'givenName' => 'Christian', 'familyName' => 'Moro' ],
			[ 'givenName' => 'Charlotte', 'familyName' => 'Phelps' ],
		], self::names( $result ) );
	}

	public function testSingleCommaBetweenTwoSingleWordsIsOneFamilyFirstAuthor(): void {
		$result = AuthorNameList::parse( 'Moro, Christian' );
		$this->assertSame(
			[ [ 'givenName' => 'Christian', 'familyName' => 'Moro' ] ],
			self::names( $result )
		);
		$this->assertSame( 'Moro, Christian', $result['names'][0]['original'] );
		$this->assertSame( 'family_first_single_comma', $result['warnings'][0]['code'] );
	}

	public function testFourSingleWordCommaSegmentsPairAsFamilyFirst(): void {
		$result = AuthorNameList::parse( 'Moro, Christian, Phelps, Charlotte' );
		$this->assertSame( [
			[ 'givenName' => 'Christian', 'familyName' => 'Moro' ],
			[ 'givenName' => 'Charlotte', 'familyName' => 'Phelps' ],
		], self::names( $result ) );
		$this->assertSame( 'ambiguous_family_first', $result['warnings'][0]['code'] );
	}

	public function testAndAndAmpersandSeparators(): void {
		$this->assertSame( [
			[ 'givenName' => 'Diana', 'familyName' => 'Daly' ],
			[ 'givenName' => 'Kainan', 'familyName' => 'Jarrette' ],
		], self::names( AuthorNameList::parse( 'Diana Daly and Kainan Jarrette' ) ) );
		$this->assertSame( [
			[ 'givenName' => 'Ana L.', 'familyName' => 'Flores-Mireles' ],
			[ 'givenName' => 'Jennifer N.', 'familyName' => 'Walker' ],
			[ 'givenName' => 'Michael', 'familyName' => 'Caparon' ],
			[ 'givenName' => 'Scott J.', 'familyName' => 'Hultgren' ],
		], self::names( AuthorNameList::parse( 'Ana L. Flores-Mireles, Jennifer N. Walker, Michael Caparon & Scott J. Hultgren' ) ) );
	}

	public function testLeadingConjunctionAfterCommaIsStripped(): void {
		$result = AuthorNameList::parse( 'David Cherney, Tom Denton, and Andrew Waldron' );
		$this->assertSame( [
			[ 'givenName' => 'David', 'familyName' => 'Cherney' ],
			[ 'givenName' => 'Tom', 'familyName' => 'Denton' ],
			[ 'givenName' => 'Andrew', 'familyName' => 'Waldron' ],
		], self::names( $result ) );

		$result = AuthorNameList::parse( 'Ana Flores, & Scott Hultgren' );
		$this->assertSame( [
			[ 'givenName' => 'Ana', 'familyName' => 'Flores' ],
			[ 'givenName' => 'Scott', 'familyName' => 'Hultgren' ],
		], self::names( $result ) );
	}

	public function testEtAlAndContributorsAreDropped(): void {
		$result = AuthorNameList::parse( 'Hass et al.' );
		$this->assertSame( [ [ 'givenName' => '', 'familyName' => 'Hass' ] ], self::names( $result ) );
		$this->assertContains( 'et_al', array_column( $result['warnings'], 'code' ) );

		$result = AuthorNameList::parse( 'Jacob Moore and Contributors' );
		$this->assertSame( [ [ 'givenName' => 'Jacob', 'familyName' => 'Moore' ] ], self::names( $result ) );
		$this->assertContains( 'non_name', array_column( $result['warnings'], 'code' ) );
	}

	public function testGenerationalSuffixStaysWithFamily(): void {
		$result = AuthorNameList::parse( 'William W. Behrens III' );
		$this->assertSame( [
			[ 'givenName' => 'William W.', 'familyName' => 'Behrens III' ],
		], self::names( $result ) );
		$this->assertContains( 'generational_suffix', $result['names'][0]['flags'] );
	}

	public function testMononymIsFlagged(): void {
		$result = AuthorNameList::parse( 'Plato' );
		$this->assertSame( [ [ 'givenName' => '', 'familyName' => 'Plato' ] ], self::names( $result ) );
		$this->assertContains( 'mononym', $result['names'][0]['flags'] );
	}

	public function testParentheticalAlternateIsRemovedBeforeSplitting(): void {
		$result = AuthorNameList::parse( 'Satoshi Watanabe (杉 森 伸 吉, 渡 辺 聡)' );
		$this->assertSame(
			[ [ 'givenName' => 'Satoshi', 'familyName' => 'Watanabe' ] ],
			self::names( $result )
		);
		$this->assertContains( 'parenthetical', array_column( $result['warnings'], 'code' ) );
	}

	public function testDuplicateNamesAreIgnored(): void {
		$result = AuthorNameList::parse( 'John Smith; John Smith' );
		$this->assertCount( 1, $result['names'] );
		$this->assertContains( 'duplicate', array_column( $result['warnings'], 'code' ) );
	}

	public function testExplicitFormatOverridesDetection(): void {
		$result = AuthorNameList::parse( 'Moro, Christian', AuthorNameList::FORMAT_SEMICOLON );
		// A single semicolon segment without a comma is given-first (the
		// family-first single-comma heuristic only applies to comma mode).
		$this->assertSame( 'Christian', $result['names'][0]['givenName'] );
		$this->assertSame( 'Moro', $result['names'][0]['familyName'] );
	}

	public function testEmptyInput(): void {
		$result = AuthorNameList::parse( '   ' );
		$this->assertSame( [], $result['names'] );
		$this->assertSame( [], $result['warnings'] );
	}

	public function testOrgPrefixCreatesACollectiveRow(): void {
		$result = AuthorNameList::parse( 'ORG:Wikimedia Foundation' );
		$this->assertCount( 1, $result['names'] );
		$row = $result['names'][0];
		$this->assertSame( 'collective', $row['kind'] );
		$this->assertSame( 'Wikimedia Foundation', $row['familyName'] );
		$this->assertSame( 'ORG:Wikimedia Foundation', $row['original'] );
		$this->assertSame( [], $row['flags'] );
	}

	public function testMixedPersonAndOrgRowsKeepTheirOrder(): void {
		$result = AuthorNameList::parse( 'Jane Doe, ORG:Wikimedia Foundation' );
		$this->assertCount( 2, $result['names'] );
		$this->assertSame( 'person', $result['names'][0]['kind'] );
		$this->assertSame( 'Jane', $result['names'][0]['givenName'] );
		$this->assertSame( 'collective', $result['names'][1]['kind'] );
		$this->assertSame( 'Wikimedia Foundation', $result['names'][1]['familyName'] );
	}

	public function testSemicolonOrgNameMayContainAComma(): void {
		$result = AuthorNameList::parse( 'ORG:Smith, Jones and Associates; Jane Doe' );
		$this->assertCount( 2, $result['names'] );
		$this->assertSame( 'collective', $result['names'][0]['kind'] );
		$this->assertSame( 'Smith, Jones and Associates', $result['names'][0]['familyName'] );
		$this->assertSame( 'person', $result['names'][1]['kind'] );
	}

	public function testEmptyOrgIsIgnoredWithAWarning(): void {
		$result = AuthorNameList::parse( 'ORG:' );
		$this->assertSame( [], $result['names'] );
		$this->assertContains( 'org_empty', array_column( $result['warnings'], 'code' ) );
	}
}
