<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../extensions/SimpleMathJax/SimpleMathJaxQuotes.php';
require_once __DIR__ . '/../../extensions/SimpleMathJax/SimpleMathJaxMultiline.php';

/**
 * Pure-PHP tests for the SimpleMathJax multiline-display patch: MathJax
 * renders a top-level `\\` as a space, so a display span carrying one is
 * wrapped in `\begin{gathered}…\end{gathered}` (environments that already
 * break lines are left alone). A top-level `\tag{…}` switches the wrapper to
 * `\begin{gather}…\end{gather}` — MathJax forbids `\tag` inside the inner
 * `gathered` environment.
 *
 * @license GPL-2.0-or-later
 */
final class SimpleMathJaxMultilineTest extends TestCase {

	private const DISPLAY = [ [ '$$', '$$' ] ];

	/**
	 * @dataProvider provideCases
	 */
	public function testWrapMultilineDisplayMath( string $input, string $expected ): void {
		$this->assertSame(
			$expected,
			\SimpleMathJaxMultiline::wrapMultilineDisplayMath( $input, self::DISPLAY )
		);
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function provideCases(): array {
		// The reported Logical_proof block: the OUTER `\\` is top-level
		// (wrap), the inner one lives in cases (untouched).
		$logicalContent = 'Pr(x): N^* \\longrightarrow \\{0,1\\} \\\\' . "\n"
			. 'Pr(x)=\\begin{cases}1 \\text{ if x is prime} \\\\ 0 \\text{ otherwise}\\end{cases}' . "\n";
		$logicalInput = '$$' . "\n" . $logicalContent . '$$';
		$logicalExpected = '$$\\begin{gathered}' . "\n" . $logicalContent . '\\end{gathered}$$';

		return [
			'simple top-level break' => [
				'$$a = b \\\\ c = d$$',
				'$$\\begin{gathered}a = b \\\\ c = d\\end{gathered}$$',
			],
			'single line display untouched' => [
				'$$a = b$$',
				'$$a = b$$',
			],
			'cases already breaks lines — no wrap' => [
				'$$\\begin{cases}1 & \\\\ 0 & \\end{cases}$$',
				'$$\\begin{cases}1 & \\\\ 0 & \\end{cases}$$',
			],
			'aligned already breaks lines — no wrap' => [
				'$$\\begin{aligned}a &= b \\\\ c &= d\\end{aligned}$$',
				'$$\\begin{aligned}a &= b \\\\ c &= d\\end{aligned}$$',
			],
			'logical proof block' => [ $logicalInput, $logicalExpected ],
			// A top-level `\tag{…}` is only allowed in a taggable OUTER
			// environment: wrap in `gather`, never `gathered` (the
			// Cubic_spline_interpolation report).
			'top-level tag uses gather' => [
				'$$a = b \\\\ c = d \\tag{1}$$',
				'$$\\begin{gather}a = b \\\\ c = d \\tag{1}\\end{gather}$$',
			],
			'top-level starred tag uses gather' => [
				'$$a = b \\\\ c = d \\tag*{1}$$',
				'$$\\begin{gather}a = b \\\\ c = d \\tag*{1}\\end{gather}$$',
			],
			// A `\tag` inside a braced group is not a top-level tag: the
			// wrapper stays `gathered`.
			'tag inside a braced group stays gathered' => [
				'$$\\text{\\tag{x}} \\\\ a = b$$',
				'$$\\begin{gathered}\\text{\\tag{x}} \\\\ a = b\\end{gathered}$$',
			],
			// A top-level environment FOLLOWED by a top-level break is still
			// multiline — wrap the whole content.
			'environment plus top-level break' => [
				'$$\\begin{cases}1 \\\\ 0\\end{cases} \\\\ x = 1$$',
				'$$\\begin{gathered}\\begin{cases}1 \\\\ 0\\end{cases} \\\\ x = 1\\end{gathered}$$',
			],
			// A `\\` inside a braced group is not a top-level break.
			'braced group break untouched' => [
				'$$\\text{a \\\\ b}$$',
				'$$\\text{a \\\\ b}$$',
			],
			// A leading space on a continuation line is MediaWiki's
			// preformatted-text marker: it would split the `$$…$$` span
			// across a <p> and a <pre> (the Sandbox:Temp report). It is
			// stripped so the span stays balanced.
			'leading-space continuation stripped' => [
				'$$' . "\n" . 'a = b \\\\' . "\n" . ' = c' . "\n" . '$$',
				'$$\\begin{gathered}' . "\n" . 'a = b \\\\' . "\n" . '= c' . "\n" . '\\end{gathered}$$',
			],
			// Same stripping on a single-content-line span (no wrap needed).
			'leading-space first line stripped' => [
				'$$' . "\n" . ' a = b' . "\n" . '$$',
				'$$' . "\n" . 'a = b' . "\n" . '$$',
			],
			// Inline delimiters are not passed, so inline math is untouched.
			'inline math untouched' => [
				'$a \\\\ b$ and prose',
				'$a \\\\ b$ and prose',
			],
			'plain text untouched' => [
				'no math here',
				'no math here',
			],
			// Unbalanced opener is left alone (MathJax ignores it).
			'unbalanced opener untouched' => [
				'$$a = b and no close',
				'$$a = b and no close',
			],
		];
	}

	public function testWrapIsIdempotent(): void {
		$once = \SimpleMathJaxMultiline::wrapMultilineDisplayMath( '$$a \\\\ b$$', self::DISPLAY );
		$twice = \SimpleMathJaxMultiline::wrapMultilineDisplayMath( $once, self::DISPLAY );
		$this->assertSame( $once, $twice );
		$this->assertSame( '$$\\begin{gathered}a \\\\ b\\end{gathered}$$', $once );
	}

	public function testGatherWrapIsIdempotent(): void {
		$once = \SimpleMathJaxMultiline::wrapMultilineDisplayMath(
			'$$a \\\\ b \\tag{1}$$',
			self::DISPLAY
		);
		$twice = \SimpleMathJaxMultiline::wrapMultilineDisplayMath( $once, self::DISPLAY );
		$this->assertSame( $once, $twice );
		$this->assertSame( '$$\\begin{gather}a \\\\ b \\tag{1}\\end{gather}$$', $once );
	}
}
