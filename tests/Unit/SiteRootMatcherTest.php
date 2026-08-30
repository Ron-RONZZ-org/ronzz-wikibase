<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Fetch\SiteRootMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Normalized-host matching for the webpage→website parent inference: the
 * root URL's host is compared against website items' URL statements.
 *
 * @covers \EmbeddableContent\Fetch\SiteRootMatcher
 * @license GPL-2.0-or-later
 */
class SiteRootMatcherTest extends TestCase {

	public function testNormalizeHost(): void {
		$this->assertSame( 'example.org', SiteRootMatcher::normalizeHost( 'https://example.org' ) );
		$this->assertSame( 'example.org', SiteRootMatcher::normalizeHost( 'https://example.org/some/page?q=1' ) );
		$this->assertSame( 'example.org', SiteRootMatcher::normalizeHost( 'https://www.example.org' ) );
		$this->assertSame( 'example.org', SiteRootMatcher::normalizeHost( 'https://WWW.Example.ORG.' ) );
		$this->assertSame( 'example.org', SiteRootMatcher::normalizeHost( 'http://example.org:8080' ) );
		// A scheme-less string is a path, not a host — never matched.
		$this->assertSame( '', SiteRootMatcher::normalizeHost( 'example.org' ) );
		// A subdomain is NOT the apex (and vice versa) — only www collapses.
		$this->assertSame( 'www2.example.org', SiteRootMatcher::normalizeHost( 'https://www2.example.org' ) );
		$this->assertSame( 'maps.example.org', SiteRootMatcher::normalizeHost( 'https://maps.example.org' ) );
	}

	public function testNormalizeHostRejectsGarbage(): void {
		$this->assertSame( '', SiteRootMatcher::normalizeHost( '' ) );
		$this->assertSame( '', SiteRootMatcher::normalizeHost( 'not a url' ) );
		$this->assertSame( '', SiteRootMatcher::normalizeHost( 'https://' ) );
	}

	public function testHostMatches(): void {
		$this->assertTrue( SiteRootMatcher::hostMatches( 'https://example.org', 'example.org' ) );
		$this->assertTrue( SiteRootMatcher::hostMatches( 'https://www.example.org/foo', 'example.org' ) );
		// The second argument is a NORMALIZED host — a www-form URL
		// normalizes to the apex and matches.
		$this->assertTrue(
			SiteRootMatcher::hostMatches( 'https://example.org', SiteRootMatcher::normalizeHost( 'https://www.example.org' ) )
		);
		$this->assertFalse( SiteRootMatcher::hostMatches( 'https://other.example.org', 'example.org' ) );
		$this->assertFalse( SiteRootMatcher::hostMatches( 'garbage', 'example.org' ) );
		$this->assertFalse( SiteRootMatcher::hostMatches( 'example.org', 'example.org' ) );
	}

	public function testFindByHostReturnsFirstMatch(): void {
		$rows = [
			[ 'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q562' ],
				'url' => [ 'type' => 'literal', 'value' => 'https://example.org' ],
				'label' => [ 'type' => 'literal', 'value' => 'Example Domain' ] ],
			[ 'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q999' ],
				'url' => [ 'type' => 'literal', 'value' => 'https://www.bbc.co.uk' ] ],
		];
		$match = SiteRootMatcher::findByHost( $rows, 'https://example.org/some/page' );
		$this->assertSame( [ 'id' => 'Q562', 'label' => 'Example Domain' ], $match );
		// www-form of the queried root matches the apex stored URL.
		$match = SiteRootMatcher::findByHost( $rows, 'https://www.bbc.co.uk/news' );
		$this->assertSame( 'Q999', $match['id'] );
		$this->assertSame( 'Q999', $match['label'] ); // label falls back to the id
	}

	public function testFindByHostNoMatch(): void {
		$rows = [
			[ 'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q1' ],
				'url' => [ 'type' => 'literal', 'value' => 'https://example.org' ] ],
		];
		$this->assertNull( SiteRootMatcher::findByHost( $rows, 'https://other-site.example/' ) );
		$this->assertNull( SiteRootMatcher::findByHost( [], 'https://example.org' ) );
		// Unparseable roots never match.
		$this->assertNull( SiteRootMatcher::findByHost( $rows, 'garbage' ) );
	}
}
