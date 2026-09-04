<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../extensions/SimpleMathJax/SimpleMathJaxQuotes.php';

/**
 * Pure-PHP tests for the SimpleMathJax quote guard (''/''' inside $…$ math
 * must be protected from MediaWiki's wikitext emphasis parsing while prose
 * italics stays untouched).
 *
 * The protector callback wraps each apostrophe run in «…» so the test can
 * assert both WHAT was protected and that everything else is byte-identical.
 *
 * @license GPL-2.0-or-later
 */
final class SimpleMathJaxQuotesTest extends TestCase {

	/**
	 * @dataProvider provideCases
	 */
	public function testProtectQuotesInMath( string $input, string $expected ): void {
		$inline = [ [ '$', '$' ] ];
		$display = [ [ '$$', '$$' ] ];
		$protect = static function ( string $run ): string {
			return '«' . $run . '»';
		};
		$this->assertSame(
			$expected,
			\SimpleMathJaxQuotes::protectQuotesInMath( $input, $protect, $inline, $display, true, true )
		);
	}

	/**
	 * @return array<int,array{string,string}>
	 */
	public static function provideCases(): array {
		return [
			// Double/triple primes inside inline math are protected.
			[ "Derivative: \$y'' = 2a_2\$ then.", "Derivative: \$y«''» = 2a_2\$ then." ],
			[ "Third: \$y''' = 1\$ here.", "Third: \$y«'''» = 1\$ here." ],
			// Single prime is not wikitext emphasis — untouched.
			[ "Derivative: \$y' = a_1\$ fine.", "Derivative: \$y' = a_1\$ fine." ],
			// Display math.
			[ "\$\$y'' - xy = 0\$\$", "\$\$y«''» - xy = 0\$\$" ],
			// Prose italics is left alone (no math delimiters around it).
			[ "plain ''italic'' and '''bold''' text", "plain ''italic'' and '''bold''' text" ],
			// Prose AFTER math is still parsed normally.
			[ "\$y'' = 1\$ and ''italic'' prose", "\$y«''» = 1\$ and ''italic'' prose" ],
			// Escaped dollars never open math.
			[ "cost \\\$5 plus ''em'' here", "cost \\\$5 plus ''em'' here" ],
			// Braced groups: close delimiter inside braces is content.
			[ "\$x^{2}'' + 1\$ end", "\$x^{2}«''» + 1\$ end" ],
			// Prime inside a braced group still protected.
			[ "\$a_{n''}\$", "\$a_{n«''»}\$" ],
			// Environments count as math (processEnvironments).
			[ "\\begin{align} y'' &= x \\end{align} then ''it''", "\\begin{align} y«''» &= x \\end{align} then ''it''" ],
			// Unbalanced $ is ignored — prose italics intact.
			[ "price \$5 then ''em'' text", "price \$5 then ''em'' text" ],
		];
	}

	/**
	 * Without any configured delimiters the text must pass through untouched.
	 */
	public function testNoDelimitersIsNoOp(): void {
		$this->assertSame(
			"text ''em'' \$y''\$",
			\SimpleMathJaxQuotes::protectQuotesInMath( "text ''em'' \$y''\$", static function ( $run ) {
				return 'X';
			}, [], [], true, true )
		);
	}

	/**
	 * Multi-span text: several math regions protected, prose between intact.
	 */
	public function testMultipleSpans(): void {
		$protect = static function ( string $run ): string {
			return '«' . $run . '»';
		};
		$input = "A \$f''(x)\$ B \$g''' = 0\$ C ''italic'' D \$\$y''\$\$ E";
		$expected = "A \$f«''»(x)\$ B \$g«'''» = 0\$ C ''italic'' D \$\$y«''»\$\$ E";
		$this->assertSame( $expected, \SimpleMathJaxQuotes::protectQuotesInMath(
			$input, $protect, [ [ '$', '$' ] ], [ [ '$$', '$$' ] ], true, true ) );
	}
}
