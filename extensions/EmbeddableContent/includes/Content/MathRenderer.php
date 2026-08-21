<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

/**
 * Renders a mathematical expression as a KaTeX-ready span. The KaTeX library
 * is loaded client-side by the embed ResourceLoader module; without JS the
 * escaped LaTeX source is shown (no-JS upgrade path deferred, issue #6 §8).
 *
 * @license GPL-2.0-or-later
 */
class MathRenderer {

	/** @var FragmentSanitizer */
	private $sanitizer;

	public function __construct( FragmentSanitizer $sanitizer ) {
		$this->sanitizer = $sanitizer;
	}

	/**
	 * Strips ONE layer of math delimiters when the WHOLE string is wrapped:
	 * $$…$$, $…$, \[…\] or \(…\). User-authored LaTeX often arrives wrapped
	 * (pasted from Markdown/MediaWiki); the stored payload — and what KaTeX
	 * renders — must be the bare TeX. Unbalanced or unwrapped input is
	 * returned unchanged.
	 */
	public static function stripDelimiters( string $input ): string {
		$pairs = [
			'/^\$\$([\s\S]*)\$\$$/',
			'/^\$([\s\S]*)\$$/',
			'/^\\\\\[([\s\S]*)\\\\\]$/',
			'/^\\\\\(([\s\S]*)\\\\\)$/',
		];
		foreach ( $pairs as $pattern ) {
			if ( preg_match( $pattern, $input, $m ) === 1 ) {
				return $m[1];
			}
		}
		return $input;
	}

	public function render( string $latex ): string {
		// Strip one layer of $$…$$ / $…$ delimiters: the client-side KaTeX
		// renderer expects bare TeX (the fallback text should match).
		$clean = self::stripDelimiters( $latex );
		// Legacy edge-strip for unbalanced historical payloads: drop a lone
		// leading/trailing $ when the strict wrapper did not match.
		if ( $clean === $latex ) {
			$clean = preg_replace( '/^\$\$?/', '', $latex ) ?? $latex;
			$clean = preg_replace( '/\$\$?$/', '', $clean ) ?? $clean;
		}
		return '<span class="wb-embed wb-embed-math" data-latex="'
			. $this->sanitizer->escapeAttribute( $clean ) . '">'
			. $this->sanitizer->escapeText( $clean )
			. '</span>';
	}
}
