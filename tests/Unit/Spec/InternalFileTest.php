<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use EmbeddableContent\Spec\InternalFile;
use PHPUnit\Framework\TestCase;

/**
 * InternalFile's pure helpers — the marker path and the signed media
 * cookie. The cookie contract must match nginx's
 * `secure_link_md5 "$exp$uid $secret"` exactly; the expected literals below
 * were produced with the same formula and are verified against a local
 * nginx instance during deployment.
 *
 * @license GPL-2.0-or-later
 */
final class InternalFileTest extends TestCase {

	public function testMarkerPathMirrorsTheRepoLayout(): void {
		$this->assertSame(
			'images/a/ab/War_and_Peace.pdf',
			InternalFile::markerRelPath( '/a/ab/War_and_Peace.pdf' )
		);
	}

	public function testSignatureIsBase64UrlOfTheNginxExpression(): void {
		// md5("17000000007 test-secret"), base64url without padding.
		$this->assertSame(
			'be7IRrY4NhalqIk3QI9rAg',
			InternalFile::signature( 1700000000, 7, 'test-secret' )
		);
	}

	public function testCookieValueCarriesExpiryUidAndSignature(): void {
		$this->assertSame(
			'1700000000.7.be7IRrY4NhalqIk3QI9rAg',
			InternalFile::cookieValue( 1700000000, 7, 'test-secret' )
		);
	}

	public function testMarkerRootMustBeOutsideTheWebRoot(): void {
		$this->assertTrue( InternalFile::isSafeMarkerRoot(
			'/var/www/wikibase-media-markers',
			'/var/www/wikibase/images',
			'/var/www/wikibase'
		) );
		$this->assertFalse( InternalFile::isSafeMarkerRoot(
			'/var/www/wikibase/media-markers',
			'/var/www/wikibase/images',
			'/var/www/wikibase'
		) );
		$this->assertFalse( InternalFile::isSafeMarkerRoot(
			'/var/www/wikibase/images/markers',
			'/var/www/wikibase/images',
			'/var/www/wikibase'
		) );
		$this->assertFalse( InternalFile::isSafeMarkerRoot(
			'',
			'/var/www/wikibase/images',
			'/var/www/wikibase'
		) );
	}

	public function testMarkerRootComparisonIgnoresTrailingSlashes(): void {
		$this->assertTrue( InternalFile::isSafeMarkerRoot(
			'/srv/wikibase-media/',
			'/var/www/wikibase/images/',
			'/var/www/wikibase/'
		) );
	}

}
