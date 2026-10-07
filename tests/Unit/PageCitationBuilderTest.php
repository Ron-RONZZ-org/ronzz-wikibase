<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WikibaseCitation\PageCitationBuilder;

/**
 * @covers \WikibaseCitation\PageCitationBuilder
 * @license GPL-2.0-or-later
 */
class PageCitationBuilderTest extends TestCase {

	public function testBuildsWebpageCslFromPageMetadata(): void {
		$csl = PageCitationBuilder::build(
			'Orthonormality',
			'https://wikibase.ronzz.org/wiki/Orthonormality',
			'Wikibase',
			'20261001131203' // TS_MW of the last revision
		);

		$this->assertSame( 'webpage', $csl['type'] );
		$this->assertSame( 'Orthonormality', $csl['title'] );
		$this->assertSame( 'Wikibase', $csl['container-title'] );
		$this->assertSame( 'https://wikibase.ronzz.org/wiki/Orthonormality', $csl['URL'] );
		$this->assertSame( [ 'date-parts' => [ [ 2026, 10, 1 ] ] ], $csl['issued'] );
		$this->assertArrayNotHasKey( 'author', $csl );
	}

	public function testIsoTimestamp(): void {
		$csl = PageCitationBuilder::build( 'Help:Contributing', 'https://x/wiki/Help:Contributing', 'Wikibase', '2026-10-03T16:15:45Z' );
		$this->assertSame( [ 'date-parts' => [ [ 2026, 10, 3 ] ] ], $csl['issued'] );
	}

	public function testYearOnlyTimestamp(): void {
		$csl = PageCitationBuilder::build( 'P', 'https://x/wiki/P', null, '2026' );
		$this->assertSame( [ 'date-parts' => [ [ 2026 ] ] ], $csl['issued'] );
		$this->assertArrayNotHasKey( 'container-title', $csl );
	}

	public function testMissingTimestampOmitsIssued(): void {
		$csl = PageCitationBuilder::build( 'P', 'https://x/wiki/P', 'Wikibase', null );
		$this->assertArrayNotHasKey( 'issued', $csl );
	}

	public function testEmptyUrlIsOmitted(): void {
		$csl = PageCitationBuilder::build( 'P', '', 'Wikibase', '2026' );
		$this->assertArrayNotHasKey( 'URL', $csl );
	}

	public function testEmptySiteNameIsOmitted(): void {
		$csl = PageCitationBuilder::build( 'P', 'https://x/wiki/P', '   ', '2026' );
		$this->assertArrayNotHasKey( 'container-title', $csl );
	}

}
