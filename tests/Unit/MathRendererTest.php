<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Content\FragmentSanitizer;
use EmbeddableContent\Content\MathRenderer;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class MathRendererTest extends TestCase {

	private function renderer(): MathRenderer {
		return new MathRenderer( new FragmentSanitizer() );
	}

	/**
	 * @dataProvider provideDelimitedInput
	 */
	public function testStripDelimiters( string $input, string $expected ): void {
		$this->assertSame( $expected, MathRenderer::stripDelimiters( $input ) );
	}

	/**
	 * @return array<int,array{string,string}>
	 */
	public static function provideDelimitedInput(): array {
		return [
			'bare tex unchanged' => [ 'E = mc^2', 'E = mc^2' ],
			'single dollar wrap' => [ '$E = mc^2$', 'E = mc^2' ],
			'double dollar wrap' => [ '$$E = mc^2$$', 'E = mc^2' ],
			'display parens' => [ '\\(E = mc^2\\)', 'E = mc^2' ],
			'display brackets' => [ '\\[E = mc^2\\]', 'E = mc^2' ],
			'multi-line display' => [ "$$\n\\int_0^1 x dx\n$$", "\n\\int_0^1 x dx\n" ],
			'outer pair only' => [ '$$x$$y$$', 'x$$y' ],
			'unbalanced leading' => [ '$E = mc^2', '$E = mc^2' ],
			'unbalanced trailing' => [ 'E = mc^2$', 'E = mc^2$' ],
			'empty input' => [ '', '' ],
			'content with inner dollars' => [ '$$a $ b$$', 'a $ b' ],
			'environment not stripped' => [ '\\begin{equation}x\\end{equation}', '\\begin{equation}x\\end{equation}' ],
		];
	}

	public function testRenderUsesStrippedLatex(): void {
		$html = $this->renderer()->render( '$$E = mc^2$$' );
		$this->assertStringContainsString( 'data-latex="E = mc^2"', $html );
		$this->assertStringContainsString( '>E = mc^2</span>', $html );
	}

	public function testRenderKeepsLegacyEdgeStripForUnbalancedInput(): void {
		// Historical payloads with a lone edge $ (saved before the form
		// started stripping) still render without it.
		$html = $this->renderer()->render( '$E = mc^2' );
		$this->assertStringContainsString( 'data-latex="E = mc^2"', $html );
	}

	public function testRenderEscapesLatex(): void {
		$html = $this->renderer()->render( '$<script>alert(1)</script>$' );
		$this->assertStringNotContainsString( '<script>', $html );
	}
}
