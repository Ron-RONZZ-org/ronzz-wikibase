<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

/**
 * Escaping primitives for fragment rendering.
 *
 * Pure PHP (no MediaWiki dependencies) so the XSS-critical escaping logic is
 * unit-testable standalone. The renderers insert *all* entity-derived text
 * through these helpers; a final `Sanitizer::removeHTMLtags()` re-pass runs in
 * the renderer against the assembled fragment (defense in depth, issue #6 §1.7).
 *
 * @license GPL-2.0-or-later
 */
class FragmentSanitizer {

	/**
	 * Escapes plain text for safe insertion into an HTML fragment.
	 */
	public function escapeText( string $text ): string {
		// Strip control characters (NUL, etc.) except whitespace.
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		return htmlspecialchars( (string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Escapes a value for safe use inside a double-quoted attribute.
	 */
	public function escapeAttribute( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Validates and returns an http(s) URL, or null when the value is not a
	 * safe URL. Used for provenance `source URL` values and href attributes.
	 */
	public function validateUrl( string $url ): ?string {
		$url = trim( $url );
		if ( $url === '' ) {
			return null;
		}
		if ( !preg_match( '#^https?://#i', $url ) ) {
			return null;
		}
		$host = parse_url( $url, PHP_URL_HOST );
		if ( !is_string( $host ) || $host === '' ) {
			return null;
		}
		// Reject URLs embedding other URL schemes in the path (javascript: etc.).
		if ( preg_match( '#javascript:|data:#i', $url ) === 1 ) {
			return null;
		}
		return $url;
	}

	/**
	 * Escapes a validated URL for use in an href attribute.
	 */
	public function escapeUrl( string $url ): string {
		return $this->escapeAttribute( $url );
	}
}
