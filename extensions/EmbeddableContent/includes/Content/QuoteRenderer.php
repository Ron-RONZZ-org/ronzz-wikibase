<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

use EmbeddableContent\EmbeddableContentConfig;

/**
 * Renders a quotation content item as a <blockquote> fragment.
 *
 * NB: v1 emits no JSON-LD <script> — the sanitizer re-pass (MW 1.46
 * removeSomeTags) bars script tags; JSON-LD in fragments was a §4.4 nicety,
 * deferred.
 *
 * @license GPL-2.0-or-later
 */
class QuoteRenderer {

	/** @var FragmentSanitizer */
	private $sanitizer;

	/** @var EmbeddableContentConfig */
	private $config;

	public function __construct( FragmentSanitizer $sanitizer, EmbeddableContentConfig $config ) {
		$this->sanitizer = $sanitizer;
		$this->config = $config;
	}

	public function render( string $text, string $lang ): string {
		return '<blockquote class="wb-embed wb-embed-quotation" lang="' . $this->sanitizer->escapeAttribute( $lang ) . '">'
			. $this->sanitizer->escapeText( $text )
			. '</blockquote>';
	}
}
