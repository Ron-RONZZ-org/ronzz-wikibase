<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use MediaWiki\Extension\GeoGebra\GeoGebraEmbed;
use PHPUnit\Framework\TestCase;

/**
 * Pure-PHP tests for the GeoGebra embed URL/attribute builders.
 *
 * The MediaWiki-bound paths (the media handler, MIME registration, the
 * rendered iframe) are covered by tests/e2e/run_geogebra_e2e.py — this suite
 * has no MediaWiki runtime.
 *
 * @covers \MediaWiki\Extension\GeoGebra\GeoGebraEmbed
 * @license GPL-2.0-or-later
 */
final class GeoGebraEmbedTest extends TestCase {

	private const PLAYER = 'https://ggb.ronzz.org/player.html';
	private const FILE = 'https://wikibase.ronzz.org/images/a/ab/Parabola.ggb';

	public function testPlayerSrcCarriesTheFileAndSizes(): void {
		$src = GeoGebraEmbed::playerSrc( self::PLAYER, self::FILE, 640, 480, 'classic' );

		$this->assertStringStartsWith( self::PLAYER . '?', $src );
		parse_str( (string)parse_url( $src, PHP_URL_QUERY ), $query );
		$this->assertSame( self::FILE, $query['file'] );
		$this->assertSame( '640', $query['w'] );
		$this->assertSame( '480', $query['h'] );
		$this->assertSame( 'classic', $query['app'] );
	}

	public function testPlayerSrcUsesAmpersandWhenThePlayerUrlAlreadyHasAQuery(): void {
		$src = GeoGebraEmbed::playerSrc( self::PLAYER . '?x=1', self::FILE, 800, 600, 'graphing' );

		$this->assertStringContainsString( '?x=1&file=', $src );
		$this->assertStringNotContainsString( '?x=1?file=', $src );
	}

	public function testIframeAttributesCarryTheIsolationContract(): void {
		$attribs = GeoGebraEmbed::iframeAttributes(
			self::PLAYER,
			self::FILE,
			800,
			600,
			'allow-scripts allow-same-origin',
			'classic'
		);

		$this->assertSame( 'ggb-embed', $attribs['class'] );
		$this->assertSame( '800', $attribs['width'] );
		$this->assertSame( '600', $attribs['height'] );
		$this->assertSame( 'allow-scripts allow-same-origin', $attribs['sandbox'] );
		$this->assertSame( 'fullscreen', $attribs['allow'] );
		$this->assertSame( 'lazy', $attribs['loading'] );
		$this->assertSame( 'no-referrer', $attribs['referrerpolicy'] );
		$this->assertStringContainsString( self::PLAYER, $attribs['src'] );
	}

	public function testConstantsMatchTheMimeContract(): void {
		$this->assertSame( 'application/geogebra', GeoGebraEmbed::MIME );
		$this->assertSame( 'ggb', GeoGebraEmbed::EXTENSION );
	}
}
