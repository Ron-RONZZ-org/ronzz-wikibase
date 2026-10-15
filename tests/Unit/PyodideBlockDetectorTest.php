<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use MediaWiki\Extension\Pyodide4MediaWiki\PyodideBlockDetector;
use PHPUnit\Framework\TestCase;

/**
 * Pure-PHP tests for the `pyodide` block detector.
 *
 * The MediaWiki-bound path (the ParserAfterTidy hook, the rendered page and
 * the browser runner) is covered by tests/e2e/run_pyodide_e2e.py and
 * tests/e2e/run_pyodide_ux_e2e.mjs — this suite has no MediaWiki runtime.
 *
 * @covers \MediaWiki\Extension\Pyodide4MediaWiki\PyodideBlockDetector
 * @license GPL-2.0-or-later
 */
final class PyodideBlockDetectorTest extends TestCase {

	public function testDetectsTheMarkerInASyntaxHighlightBlock(): void {
		// The exact class list SyntaxHighlight emits for
		// <syntaxhighlight lang="python" class="pyodide">.
		$html = '<div class="pyodide mw-highlight mw-highlight-lang-python mw-content-ltr dir" dir="ltr">'
			. '<pre>print(&quot;hi&quot;)</pre></div>';
		$this->assertTrue( PyodideBlockDetector::containsPyodideBlock( $html ) );
	}

	public function testDetectsTheMarkerAmongOtherClasses(): void {
		$this->assertTrue(
			PyodideBlockDetector::containsPyodideBlock( '<pre class="foo pyodide bar"></pre>' )
		);
	}

	public function testDetectsSingleQuotedAttributes(): void {
		$this->assertTrue(
			PyodideBlockDetector::containsPyodideBlock( "<pre class='pyodide'></pre>" )
		);
	}

	public function testIgnoresAClassThatMerelyContainsTheMarker(): void {
		$this->assertFalse(
			PyodideBlockDetector::containsPyodideBlock( '<pre class="pyodide-something"></pre>' )
		);
		$this->assertFalse(
			PyodideBlockDetector::containsPyodideBlock( '<pre class="my-pyodide"></pre>' )
		);
	}

	public function testDoesNotMatchOtherAttributes(): void {
		$this->assertFalse(
			PyodideBlockDetector::containsPyodideBlock( '<div data-class="pyodide"></div>' )
		);
	}

	public function testProseMentioningTheMarkerIsNotABlock(): void {
		$this->assertFalse(
			PyodideBlockDetector::containsPyodideBlock(
				'<p>The pyodide marker opts a block in.</p>'
			)
		);
	}

	public function testPlainHighlightedCodeIsNotDetected(): void {
		$html = '<div class="mw-highlight mw-highlight-lang-python" dir="ltr">'
			. '<pre>print(&quot;hi&quot;)</pre></div>';
		$this->assertFalse( PyodideBlockDetector::containsPyodideBlock( $html ) );
	}

	public function testEmptyInput(): void {
		$this->assertFalse( PyodideBlockDetector::containsPyodideBlock( '' ) );
	}
}
