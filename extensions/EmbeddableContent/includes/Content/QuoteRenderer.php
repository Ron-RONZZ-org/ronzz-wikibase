<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

use EmbeddableContent\EmbeddableContentConfig;

/**
 * Renders a quotation content item as a <blockquote> fragment with JSON-LD.
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
		$jsonLd = json_encode( [
			'@context' => 'https://schema.org',
			'@type' => 'Quotation',
			'text' => $text,
			'inLanguage' => $lang,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return '<blockquote class="wb-embed wb-embed-quotation" lang="' . $this->sanitizer->escapeAttribute( $lang ) . '">'
			. $this->sanitizer->escapeText( $text )
			. '</blockquote>'
			. '<script type="application/ld+json">' . $this->escapeJson( $jsonLd ) . '</script>';
	}

	/**
	 * Escapes a JSON string for safe embedding in a <script> element.
	 */
	private function escapeJson( string $json ): string {
		return str_replace( '</', '<\\/', $json );
	}
}
