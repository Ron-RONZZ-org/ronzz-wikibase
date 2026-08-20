<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WikibaseCitation\CitationSanitizer;

/**
 * Allowlist sanitizer tests: citeproc-php HTML embeds user-entered
 * statement values, so disallowed tags/attributes must never survive.
 *
 * @license GPL-2.0-or-later
 */
class CitationSanitizerTest extends TestCase {

	private CitationSanitizer $sanitizer;

	protected function setUp(): void {
		$this->sanitizer = new CitationSanitizer();
	}

	public function testAllowsWhitelistedInlineTags(): void {
		$html = '<i>title</i><b>bold</b><em>em</em><strong>strong</strong>';
		$this->assertSame( $html, $this->sanitizer->sanitizeHtml( $html ) );
	}

	public function testKeepsSpanWithClassAttribute(): void {
		$html = '<span class="csl-entry">text</span>';
		$this->assertSame( $html, $this->sanitizer->sanitizeHtml( $html ) );
	}

	public function testStripsNonClassAttributes(): void {
		$html = '<span class="csl-entry" onclick="alert(1)" style="color:red" id="x">text</span>';
		$this->assertSame( '<span class="csl-entry">text</span>', $this->sanitizer->sanitizeHtml( $html ) );
	}

	public function testStripsScriptTagButKeepsText(): void {
		$html = '<script>alert("xss")</script>citation text';
		$this->assertSame( 'alert("xss")citation text', $this->sanitizer->sanitizeHtml( $html ) );
	}

	public function testStripsAnchorAndImageTags(): void {
		$html = '<a href="javascript:alert(1)">link</a><img src="x" onerror="alert(1)">';
		$this->assertSame( 'link', $this->sanitizer->sanitizeHtml( $html ) );
	}

	public function testStripsDivWrapper(): void {
		$html = '<div class="csl-entry">content</div>';
		$this->assertSame( 'content', $this->sanitizer->sanitizeHtml( $html ) );
	}

	public function testRejectsUppercaseTags(): void {
		// Tag names are lowercased before the allowlist check (allowed tags
		// are re-emitted lowercase; disallowed ones are stripped).
		$this->assertSame( '<i>x</i>', $this->sanitizer->sanitizeHtml( '<I>x</I>' ) );
		$this->assertSame( 'x', $this->sanitizer->sanitizeHtml( '<SCRIPT>x</SCRIPT>' ) );
	}

	public function testRemovesComments(): void {
		$this->assertSame( 'ab', $this->sanitizer->sanitizeHtml( 'a<!-- hidden -->b' ) );
	}

	public function testDropsClassAttributeWithMaliciousValue(): void {
		$html = '<span class="csl-entry&quot;&gt;&lt;script&gt;">x</span>';
		$this->assertSame( '<span>x</span>', $this->sanitizer->sanitizeHtml( $html ) );
	}

	public function testUnbalancedDisallowedTagTextIsKept(): void {
		$this->assertSame( 'text', $this->sanitizer->sanitizeHtml( 'text<iframe>' ) );
	}

	public function testWhitespaceRunsAreCollapsed(): void {
		// citeproc's div structure leaves "\n  " indentation around the
		// stripped tags — it must not survive as pre-wrapping whitespace.
		$html = "<div class=\"csl-bib-body\">\n  <div class=\"csl-entry\">Notes du traducteur. (n.d.).</div>\n</div>";
		$this->assertSame( 'Notes du traducteur. (n.d.).', $this->sanitizer->sanitizeHtml( $html ) );
	}

	public function testInlineTagsKeepSingleSpaces(): void {
		$html = 'In <i>Notes du traducteur</i>. R. &amp; J. E. Taylor.';
		$this->assertSame( $html, $this->sanitizer->sanitizeHtml( $html ) );
	}
}
