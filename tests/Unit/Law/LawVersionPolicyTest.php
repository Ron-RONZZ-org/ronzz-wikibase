<?php

declare( strict_types = 1 );

namespace Tests\Unit\Law;

use EmbeddableContent\Law\LawVersionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The LEGI tree carries every article version; the store keeps one row per
 * reference. v1 kept the last iterated version, which served abrogated text
 * (Code civil art. 9 was the 1927 text).
 *
 * @license GPL-2.0-or-later
 */
class LawVersionPolicyTest extends TestCase {

	public function testInForceIsVigueurOnly(): void {
		$this->assertTrue( LawVersionPolicy::isInForce( [ 'status' => 'VIGUEUR' ] ) );
		$this->assertFalse( LawVersionPolicy::isInForce( [ 'status' => 'MODIFIE' ] ) );
		$this->assertFalse( LawVersionPolicy::isInForce( [ 'status' => 'ABROGE' ] ) );
	}

	public function testVigueurBeatsHistoricalVersions(): void {
		$this->assertTrue( LawVersionPolicy::isPreferred(
			[ 'status' => 'VIGUEUR', 'version_start' => '2008-07-25' ],
			[ 'status' => 'MODIFIE', 'version_start' => '1958-10-05' ]
		) );
		$this->assertFalse( LawVersionPolicy::isPreferred(
			[ 'status' => 'ABROGE', 'version_start' => '1970-07-19' ],
			[ 'status' => 'VIGUEUR', 'version_start' => '1970-07-20' ]
		) );
	}

	public function testTheLatestStartWinsAmongTheSameState(): void {
		$this->assertTrue( LawVersionPolicy::isPreferred(
			[ 'status' => 'VIGUEUR', 'version_start' => '2020-01-01' ],
			[ 'status' => 'VIGUEUR', 'version_start' => '2010-01-01' ]
		) );
		$this->assertFalse( LawVersionPolicy::isPreferred(
			[ 'status' => 'VIGUEUR', 'version_start' => '2010-01-01' ],
			[ 'status' => 'VIGUEUR', 'version_start' => '2020-01-01' ]
		) );
	}
}
