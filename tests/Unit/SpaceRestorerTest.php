<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use CodeBlockSpaces\SpaceRestorer;
use PHPUnit\Framework\TestCase;

/**
 * Pure-PHP tests for the CodeBlockSpaces restorer: it reverts MediaWiki's
 * French-space armoring inside `<pre>`/`<code>` only, and leaves prose
 * armoring untouched.
 *
 * The MediaWiki-bound path (the `ParserAfterTidy` hook) is covered by
 * tests/e2e/run_codeblock_spaces_e2e.py — this suite has no MediaWiki
 * runtime.
 *
 * @covers \CodeBlockSpaces\SpaceRestorer
 * @license GPL-2.0-or-later
 */
final class SpaceRestorerTest extends TestCase {

	public function testRestoresArmoredSpacesInsidePre(): void {
		$this->assertSame(
			'<pre>a != b ? c : d</pre>',
			SpaceRestorer::restore( '<pre>a&#160;!= b&#160;? c&#160;: d</pre>' )
		);
	}

	public function testRestoresArmoredSpacesInsideCode(): void {
		$this->assertSame(
			'<code>x != y</code>',
			SpaceRestorer::restore( '<code>x&#160;!= y</code>' )
		);
	}

	public function testRestoresNamedNbsp(): void {
		$this->assertSame(
			'<pre>a ! b</pre>',
			SpaceRestorer::restore( '<pre>a&nbsp;! b</pre>' )
		);
	}

	public function testLeavesProseArmoringUntouched(): void {
		$html = '<p>a&#160;? b&#160;: c</p>';
		$this->assertSame( $html, SpaceRestorer::restore( $html ) );
	}

	public function testOnlyTheCodeBlockIsTouched(): void {
		$this->assertSame(
			'<p>a&#160;?</p><pre>b ?</pre>',
			SpaceRestorer::restore( '<p>a&#160;?</p><pre>b&#160;?</pre>' )
		);
	}

	public function testHandlesSyntaxHighlightMarkup(): void {
		$html = '<div class="mw-highlight mw-highlight-lang-text">'
			. '<pre><span></span>:%s/a&#160;? b/</pre></div>';
		$this->assertSame(
			'<div class="mw-highlight mw-highlight-lang-text">'
				. '<pre><span></span>:%s/a ? b/</pre></div>',
			SpaceRestorer::restore( $html )
		);
	}

	public function testRestoresCodeNestedInsidePre(): void {
		$this->assertSame(
			'<pre>a ? b <code>c : d</code></pre>',
			SpaceRestorer::restore( '<pre>a&#160;? b <code>c&#160;: d</code></pre>' )
		);
	}

	public function testHandlesAttributesAndUppercaseTags(): void {
		$this->assertSame(
			'<PRE class="x">a ? b</PRE>',
			SpaceRestorer::restore( '<PRE class="x">a&#160;? b</PRE>' )
		);
	}

	public function testIsIdempotent(): void {
		$once = SpaceRestorer::restore( '<pre>a&#160;? b</pre>' );
		$this->assertSame( $once, SpaceRestorer::restore( $once ) );
	}

	public function testEmptyInput(): void {
		$this->assertSame( '', SpaceRestorer::restore( '' ) );
	}

	public function testFastPathLeavesUnarmoredHtmlAlone(): void {
		$html = '<pre>a ? b</pre><code>c : d</code>';
		$this->assertSame( $html, SpaceRestorer::restore( $html ) );
	}
}
