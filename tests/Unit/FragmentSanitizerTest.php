<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Content\FragmentSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * XSS-critical escaping logic of the embed renderer (issue #6 §9.4).
 * Runs standalone — no MediaWiki needed.
 *
 * @license GPL-2.0-or-later
 */
class FragmentSanitizerTest extends TestCase {

	private FragmentSanitizer $sanitizer;

	protected function setUp(): void {
		$this->sanitizer = new FragmentSanitizer();
	}

	public function testScriptTagInjectionIsEscaped(): void {
		$text = '<script>alert(1)</script>';
		$this->assertStringNotContainsString( '<script', $this->sanitizer->escapeText( $text ) );
		$this->assertStringContainsString( '&lt;script&gt;', $this->sanitizer->escapeText( $text ) );
	}

	public function testEventHandlerAttributeIsEscaped(): void {
		// The tag must not parse — the literal word "onerror" surviving as
		// inert escaped text is fine; '<img' must not.
		$out = $this->sanitizer->escapeText( '<img src=x onerror=alert(1)>' );
		$this->assertStringNotContainsString( '<img', $out );
		$this->assertStringContainsString( '&lt;img', $out );
	}

	public function testJavascriptUrlInAttributeContextIsUnparseable(): void {
		// A javascript: URL is rejected at URL-validation time; as plain text
		// it is inert. Verify both properties.
		$this->assertNull( $this->sanitizer->validateUrl( 'javascript:alert(1)' ) );
		$out = $this->sanitizer->escapeText( '<a href="javascript:alert(1)">x</a>' );
		$this->assertStringNotContainsString( '<a', $out );
	}

	public function testUnicodeScriptInjectionIsInertText(): void {
		// Full-width angle brackets are not markup; they must pass through
		// unchanged and never be half-width normalized into markup.
		$text = "＜script＞alert(1)＜/script＞";
		$this->assertSame( $text, $this->sanitizer->escapeText( $text ) );
	}

	public function testControlCharactersAreStripped(): void {
		$out = $this->sanitizer->escapeText( "abc\x00\x1fdef" );
		$this->assertSame( 'abcdef', $out );
	}

	public function testPlainTextPassesThroughEscaped(): void {
		$this->assertSame( 'a &amp; b', $this->sanitizer->escapeText( 'a & b' ) );
		$this->assertSame( '&quot;quoted&quot;', $this->sanitizer->escapeText( '"quoted"' ) );
	}

	public function testValidateUrlAcceptsHttpHttps(): void {
		$this->assertSame( 'https://example.org/x', $this->sanitizer->validateUrl( 'https://example.org/x' ) );
		$this->assertSame( 'http://example.org', $this->sanitizer->validateUrl( 'http://example.org' ) );
	}

	public function testValidateUrlRejectsUnsafeSchemes(): void {
		$this->assertNull( $this->sanitizer->validateUrl( 'javascript:alert(1)' ) );
		$this->assertNull( $this->sanitizer->validateUrl( 'data:text/html,<script>' ) );
		$this->assertNull( $this->sanitizer->validateUrl( 'ftp://example.org/x' ) );
		$this->assertNull( $this->sanitizer->validateUrl( '' ) );
		$this->assertNull( $this->sanitizer->validateUrl( 'not a url' ) );
	}

	public function testValidateUrlRejectsUrlWithoutHost(): void {
		$this->assertNull( $this->sanitizer->validateUrl( 'https://' ) );
	}

	public function testEscapeAttribute(): void {
		$this->assertSame(
			'&quot;&gt;&lt;script&gt;&lt;/script&gt;',
			$this->sanitizer->escapeAttribute( '"><script></script>' )
		);
	}
}
