<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Tests\Unit\Spec;

use EmbeddableContent\Spec\UserPageTitle;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Spec\UserPageTitle
 * @license GPL-2.0-or-later
 */
class UserPageTitleTest extends TestCase {

	public function testSplitsUserAndSubpage(): void {
		$this->assertSame(
			[ 'user' => 'Rongzhou', 'title' => 'Pretty good Linux' ],
			UserPageTitle::split( 'Rongzhou/Pretty good Linux' )
		);
	}

	public function testKeepsTheFullNestedSubpageRemainder(): void {
		$this->assertSame(
			[ 'user' => 'Rongzhou', 'title' => 'Pretty good Linux/v2' ],
			UserPageTitle::split( 'Rongzhou/Pretty good Linux/v2' )
		);
	}

	public function testRootPageIsNotASubpage(): void {
		$this->assertNull( UserPageTitle::split( 'Rongzhou' ) );
	}

	public function testLeadingOrTrailingSlashIsRejected(): void {
		$this->assertNull( UserPageTitle::split( '/Pretty good Linux' ) );
		$this->assertNull( UserPageTitle::split( 'Rongzhou/' ) );
	}

	public function testEmptyInputIsRejected(): void {
		$this->assertNull( UserPageTitle::split( '' ) );
	}

}
