<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\SameOrigin;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Spec\SameOrigin
 * @license GPL-2.0-or-later
 */
class SameOriginTest extends TestCase {

	public function testSameHostMatches(): void {
		$this->assertTrue( SameOrigin::matches(
			'https://wikibase.ronzz.org',
			'https://wikibase.ronzz.org/wiki/Main_Page'
		) );
		// Scheme/port differences do not cross an attacker-controlled host.
		$this->assertTrue( SameOrigin::matches(
			'http://wikibase.ronzz.org:8080',
			'https://wikibase.ronzz.org/wiki/Main_Page'
		) );
	}

	public function testDifferentHostDoesNotMatch(): void {
		$this->assertFalse( SameOrigin::matches(
			'https://evil.example/wiki/Main_Page',
			'https://wikibase.ronzz.org/wiki/Main_Page'
		) );
		$this->assertFalse( SameOrigin::matches(
			'https://sub.wikibase.ronzz.org',
			'https://wikibase.ronzz.org/wiki/Main_Page'
		) );
	}

	public function testMissingOrMalformedOriginDoesNotMatch(): void {
		$this->assertFalse( SameOrigin::matches( '', 'https://wikibase.ronzz.org/wiki/Main_Page' ) );
		$this->assertFalse( SameOrigin::matches( 'null', 'https://wikibase.ronzz.org/wiki/Main_Page' ) );
		$this->assertFalse( SameOrigin::matches( 'not a url', 'https://wikibase.ronzz.org/wiki/Main_Page' ) );
	}

}
