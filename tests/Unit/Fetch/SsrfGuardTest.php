<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\SsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class SsrfGuardTest extends TestCase {

	/** @return iterable<string,array{string,bool}> */
	public static function hostProvider(): iterable {
		yield 'ordinary host' => [ 'https://www.example.com/', true ];
		yield 'no scheme' => [ 'www.example.com', false ];
		yield 'ftp scheme' => [ 'ftp://example.com/file', false ];
		yield 'javascript scheme' => [ 'javascript:alert(1)', false ];
		yield 'localhost' => [ 'https://localhost/x', false ];
		yield 'localhost subdomain' => [ 'https://foo.localhost/x', false ];
		yield 'dot-local' => [ 'https://nas.local/x', false ];
		yield 'dot-internal' => [ 'https://intranet.internal/x', false ];
		yield 'single label' => [ 'https://intranet/x', false ];
		yield 'private ipv4 10' => [ 'http://10.0.0.1/x', false ];
		yield 'private ipv4 192.168' => [ 'http://192.168.1.1/x', false ];
		yield 'private ipv4 172.16' => [ 'http://172.16.0.1/x', false ];
		yield 'loopback ipv4' => [ 'http://127.0.0.1/x', false ];
		yield 'link-local ipv4' => [ 'http://169.254.169.254/latest/meta-data', false ];
		yield 'CGNAT ipv4' => [ 'http://100.64.0.1/x', false ];
		yield 'multicast ipv4' => [ 'http://224.0.0.1/x', false ];
		yield 'TEST-NET' => [ 'http://192.0.2.1/x', false ];
		yield 'public ipv4' => [ 'http://8.8.8.8/x', true ];
		yield 'loopback ipv6' => [ 'http://[::1]/x', false ];
		yield 'ULA ipv6' => [ 'http://[fd00::1]/x', false ];
		yield 'link-local ipv6' => [ 'http://[fe80::1]/x', false ];
		yield 'public ipv6' => [ 'http://[2606:4700:4700::1111]/x', true ];
		yield 'embedded credentials' => [ 'https://user:pass@example.com/x', false ];
		yield 'bad port' => [ 'https://example.com:99999/x', false ];
		yield 'blank' => [ '', false ];
	}

	/** @dataProvider hostProvider */
	public function testValidate( string $url, bool $expected ): void {
		$result = SsrfGuard::validate( $url );
		if ( $expected ) {
			$this->assertNotNull( $result, "expected {$url} to be accepted" );
		} else {
			$this->assertNull( $result, "expected {$url} to be rejected" );
		}
	}

	public function testValidateDropsFragmentAndNormalizesHostCase(): void {
		$this->assertSame( 'https://Example.COM/Path?q=1', SsrfGuard::validate( 'HTTPS://Example.COM/Path?q=1#frag' ) );
	}

	public function testSiteRootCollapsesPathAndQuery(): void {
		$this->assertSame(
			'https://www.bbc.co.uk',
			SsrfGuard::siteRoot( 'https://www.bbc.co.uk/article1?utm=1' )
		);
		$this->assertSame(
			'http://example.com:8080',
			SsrfGuard::siteRoot( 'http://example.com:8080/x/y' )
		);
	}
}
