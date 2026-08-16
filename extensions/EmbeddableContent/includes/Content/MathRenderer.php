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

	public function render( string $latex ): string {
		return '<span class="wb-embed wb-embed-math" data-latex="'
			. $this->sanitizer->escapeAttribute( $latex ) . '">'
			. $this->sanitizer->escapeText( $latex )
			. '</span>';
	}
}
