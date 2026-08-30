<?php

declare( strict_types = 1 );

namespace Tests\Unit\Content;

use EmbeddableContent\Content\PayloadCodec;
use PHPUnit\Framework\TestCase;

/**
 * PayloadCodec — the escape-at-rest / decode-at-render codec for content
 * item payloads (issue #6 §8 escalation, option A).
 *
 * The wiki's string and monolingualtext values reject vertical whitespace
 * and tabs, so multi-line payloads are stored backslash-escaped and decoded
 * on output. The codec must be lossless in both directions, including for
 * content that itself contains backslash sequences.
 *
 * @license GPL-2.0-or-later
 */
final class PayloadCodecTest extends TestCase {

	public function testEscapeEncodesNewlinesCarriageReturnsAndTabs(): void {
		$this->assertSame( 'a\\nb', PayloadCodec::escape( "a\nb" ) );
		$this->assertSame( 'a\\rb', PayloadCodec::escape( "a\rb" ) );
		$this->assertSame( 'a\\tb', PayloadCodec::escape( "a\tb" ) );
	}

	public function testEscapeEncodesBackslashesFirst(): void {
		// A literal backslash becomes \\, so a literal "\n" stays distinct
		// from an escaped newline.
		$this->assertSame( 'a\\\\nb', PayloadCodec::escape( 'a\\nb' ) );
	}

	public function testDecodeRestoresWhitespace(): void {
		$this->assertSame( "a\nb", PayloadCodec::decode( 'a\\nb' ) );
		$this->assertSame( "a\rb", PayloadCodec::decode( 'a\\rb' ) );
		$this->assertSame( "a\tb", PayloadCodec::decode( 'a\\tb' ) );
	}

	public function testDecodeKeepsLiteralBackslashSequences(): void {
		// The stored form of a literal "\n" is "\\n" and must decode back to
		// a literal backslash-n, not a newline.
		$this->assertSame( 'a\\nb', PayloadCodec::decode( 'a\\\\nb' ) );
	}

	public function testRoundTripIsLossless(): void {
		$samples = [
			'single line',
			"two\nlines",
			"a\nb\tc\rd",
			'print("a\\nb")',
			'\\\\network\\share',
			'  leading and trailing  ',
			"line1\nline2\nline3",
			'',
		];
		foreach ( $samples as $sample ) {
			$this->assertSame( $sample, PayloadCodec::decode( PayloadCodec::escape( $sample ) ) );
		}
	}

	public function testEscapeResultContainsNoRejectedWhitespace(): void {
		// The escaped form must satisfy the wiki's string-value validator:
		// no newline, carriage return or tab anywhere.
		foreach ( [ "a\nb", "a\rb", "a\tb", "a\nb\tc\rd" ] as $sample ) {
			$escaped = PayloadCodec::escape( $sample );
			$this->assertStringNotContainsString( "\n", $escaped );
			$this->assertStringNotContainsString( "\r", $escaped );
			$this->assertStringNotContainsString( "\t", $escaped );
		}
	}
}
