<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Tests\Unit\Spec;

use EmbeddableContent\Spec\ImageCredit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Spec\ImageCredit
 * @license GPL-2.0-or-later
 */
class ImageCreditTest extends TestCase {

	public function testJoinsNonEmptyPartsWithThePrefix(): void {
		$this->assertSame(
			'Image: NASA · Public domain · Blue Marble',
			ImageCredit::line( [ 'NASA', '', ' ', 'Public domain', 'Blue Marble' ], 'Image' )
		);
	}

	public function testNoPrefixRendersJustTheParts(): void {
		$this->assertSame( 'NASA · Public domain', ImageCredit::line( [ 'NASA', 'Public domain' ] ) );
	}

	public function testNoPartsRenderNothing(): void {
		$this->assertSame( '', ImageCredit::line( [ '', ' ' ], 'Image' ) );
	}

	public function testStripsTheNoCreditClassToken(): void {
		$this->assertSame(
			[ 'found' => true, 'class' => '' ],
			ImageCredit::stripNoCreditClass( 'noCredit' )
		);
		$this->assertSame(
			[ 'found' => true, 'class' => 'foo bar' ],
			ImageCredit::stripNoCreditClass( 'foo noCredit bar' )
		);
		$this->assertSame(
			[ 'found' => true, 'class' => 'foo' ],
			ImageCredit::stripNoCreditClass( 'noCredit foo' )
		);
		$this->assertSame(
			[ 'found' => true, 'class' => 'foo' ],
			ImageCredit::stripNoCreditClass( 'foo noCredit' )
		);
	}

	public function testClassTokensAreCaseInsensitive(): void {
		$this->assertTrue( ImageCredit::stripNoCreditClass( 'NOCredit' )['found'] );
	}

	public function testDoesNotMatchInsideAnotherToken(): void {
		$this->assertSame(
			[ 'found' => false, 'class' => 'my-noCredit-class' ],
			ImageCredit::stripNoCreditClass( 'my-noCredit-class' )
		);
	}

	public function testBareCaptionIsTheFlag(): void {
		$this->assertTrue( ImageCredit::isNoCreditCaption( 'noCredit' ) );
		$this->assertTrue( ImageCredit::isNoCreditCaption( '  NOCredit ' ) );
		$this->assertFalse( ImageCredit::isNoCreditCaption( 'noCredit and a caption' ) );
		$this->assertFalse( ImageCredit::isNoCreditCaption( '' ) );
	}

}
