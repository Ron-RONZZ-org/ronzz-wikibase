<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Fetch\SiteRootMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Normalized-host matching for the webpage→website parent inference: the
 * root URL's host is compared against website items' URL statements — the
 * recorded host may be the page host itself or an ANCESTOR domain (a
 * webpage on scifa.univ-lorraine.fr is part of the univ-lorraine.fr
 * site), and the most specific recorded host wins.
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
		// Only www collapses — other subdomains stay as-is (the ancestor
		// matching happens in findByHost, not here).
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

	public function testHostIsSelfOrAncestor(): void {
		// The recorded host equals the page host, or is one of its parent
		// domains (www collapses on both sides).
		$this->assertTrue( SiteRootMatcher::hostIsSelfOrAncestor( 'https://example.org', 'example.org' ) );
		$this->assertTrue( SiteRootMatcher::hostIsSelfOrAncestor( 'https://www.example.org', 'example.org' ) );
		$this->assertTrue(
			SiteRootMatcher::hostIsSelfOrAncestor( 'https://www.univ-lorraine.fr', 'scifa.univ-lorraine.fr' )
		);
		$this->assertTrue(
			SiteRootMatcher::hostIsSelfOrAncestor( 'https://univ-lorraine.fr', 'a.b.scifa.univ-lorraine.fr' )
		);
		// Siblings / unrelated / a SUBdomain of the recorded host are NOT
		// the recorded site's pages (the page host must be BELOW the record).
		$this->assertFalse(
			SiteRootMatcher::hostIsSelfOrAncestor( 'https://scifa.univ-lorraine.fr', 'univ-lorraine.fr' )
		);
		$this->assertFalse( SiteRootMatcher::hostIsSelfOrAncestor( 'https://example.org', 'example.com' ) );
		$this->assertFalse( SiteRootMatcher::hostIsSelfOrAncestor( 'https://example.org', 'other-site.example' ) );
		$this->assertFalse( SiteRootMatcher::hostIsSelfOrAncestor( 'garbage', 'example.org' ) );
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

	public function testFindByHostSubdomainMatchesAncestorRecord(): void {
		$rows = [
			[ 'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q700' ],
				'url' => [ 'type' => 'literal', 'value' => 'https://www.univ-lorraine.fr' ],
				'label' => [ 'type' => 'literal', 'value' => 'Université de Lorraine' ] ],
		];
		// scifa.univ-lorraine.fr is a webpage of the univ-lorraine.fr site —
		// the ancestor-domain match assigns the parent (the request's own
		// example). Works for deep subdomains and www2-style hosts too.
		$match = SiteRootMatcher::findByHost( $rows, 'https://scifa.univ-lorraine.fr/some/page' );
		$this->assertSame( [ 'id' => 'Q700', 'label' => 'Université de Lorraine' ], $match );
		$match = SiteRootMatcher::findByHost( $rows, 'https://a.b.scifa.univ-lorraine.fr/x' );
		$this->assertSame( 'Q700', $match['id'] );
		$match = SiteRootMatcher::findByHost( $rows, 'https://www2.univ-lorraine.fr' );
		$this->assertSame( 'Q700', $match['id'] );
	}

	public function testFindByHostMostSpecificAncestorWins(): void {
		$rows = [
			[ 'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q700' ],
				'url' => [ 'type' => 'literal', 'value' => 'https://univ-lorraine.fr' ],
				'label' => [ 'type' => 'literal', 'value' => 'Université de Lorraine' ] ],
			[ 'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q701' ],
				'url' => [ 'type' => 'literal', 'value' => 'https://scifa.univ-lorraine.fr' ],
				'label' => [ 'type' => 'literal', 'value' => 'SCIFA' ] ],
		];
		// The page's OWN host record wins over the parent-domain record…
		$match = SiteRootMatcher::findByHost( $rows, 'https://scifa.univ-lorraine.fr/a-page' );
		$this->assertSame( 'Q701', $match['id'] );
		// …and for a deeper page host the CLOSEST recorded ancestor wins.
		$match = SiteRootMatcher::findByHost( $rows, 'https://a.b.scifa.univ-lorraine.fr/x' );
		$this->assertSame( 'Q701', $match['id'] );
		// No scifa record → the parent-domain record is the best match.
		$rowsNoScifa = [ $rows[0] ];
		$match = SiteRootMatcher::findByHost( $rowsNoScifa, 'https://scifa.univ-lorraine.fr/a-page' );
		$this->assertSame( 'Q700', $match['id'] );
	}

	public function testFindByHostNoMatch(): void {
		$rows = [
			[ 'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q1' ],
				'url' => [ 'type' => 'literal', 'value' => 'https://example.org' ] ],
		];
		$this->assertNull( SiteRootMatcher::findByHost( $rows, 'https://other-site.example/' ) );
		$this->assertNull( SiteRootMatcher::findByHost( $rows, 'https://example.com' ) );
		$this->assertNull( SiteRootMatcher::findByHost( [], 'https://example.org' ) );
		// Unparseable roots never match.
		$this->assertNull( SiteRootMatcher::findByHost( $rows, 'garbage' ) );
	}

	public function testFindByHostRecordBelowPageHostNeverMatches(): void {
		// The match goes UP the page host's own suffix chain: a recorded
		// website for scifa.univ-lorraine.fr is a SUBdomain of a page on
		// univ-lorraine.fr — the subdomain site is not the parent of the
		// apex page (the reverse direction of the feature).
		$rows = [
			[ 'item' => [ 'type' => 'uri', 'value' => 'https://wikibase.ronzz.org/entity/Q701' ],
				'url' => [ 'type' => 'literal', 'value' => 'https://scifa.univ-lorraine.fr' ] ],
		];
		$this->assertNull( SiteRootMatcher::findByHost( $rows, 'https://univ-lorraine.fr' ) );
		// A deeper page host still matches the scifa record (ancestor).
		$match = SiteRootMatcher::findByHost( $rows, 'https://a.scifa.univ-lorraine.fr/page' );
		$this->assertSame( 'Q701', $match['id'] );
	}
}
