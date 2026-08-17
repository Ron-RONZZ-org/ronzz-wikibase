<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

use EmbeddableContent\EmbeddableContentConfig;

/**
 * Renders a code snippet via the stock SyntaxHighlight (Pygments) extension,
 * falling back to an escaped <pre> when the extension is unavailable or the
 * lexer rejects the input. The lexer comes from the config map (language item
 * => Pygments lexer name); unknown languages fall back to "text".
 *
 * @license GPL-2.0-or-later
 */
class CodeRenderer {

	/** @var FragmentSanitizer */
	private $sanitizer;

	/** @var EmbeddableContentConfig */
	private $config;

	public function __construct( FragmentSanitizer $sanitizer, EmbeddableContentConfig $config ) {
		$this->sanitizer = $sanitizer;
		$this->config = $config;
	}

	public function render( string $code, string $lexer ): string {
		$highlighted = $this->trySyntaxHighlight( $code, $lexer );
		if ( $highlighted !== null ) {
			return '<div class="wb-embed wb-embed-code" data-lexer="' . $this->sanitizer->escapeAttribute( $lexer ) . '">'
				. $highlighted . '</div>';
		}
		return '<pre class="wb-embed wb-embed-code" data-lexer="' . $this->sanitizer->escapeAttribute( $lexer ) . '">'
			. $this->sanitizer->escapeText( $code ) . '</pre>';
	}

	private function trySyntaxHighlight( string $code, string $lexer ): ?string {
		$syntaxHighlightClass = 'MediaWiki\\Extension\\SyntaxHighlight\\SyntaxHighlight';
		if ( !class_exists( $syntaxHighlightClass ) ) {
			return null;
		}
		try {
			$result = $syntaxHighlightClass::highlight( $code, $lexer );
			// highlight() returns Wikimedia\HtmlArmor since MW 1.35.
			if ( $result instanceof \Wikimedia\HtmlArmor ) {
				return $result->getHtml();
			}
			return is_string( $result ) ? $result : null;
		} catch ( \Throwable $e ) {
			// Bad lexer name or pygmentize failure — fall back to escaped text.
			return null;
		}
	}
}
