<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

/**
 * Allowlist HTML sanitizer for citation output (issue #24, ADR
 * `docs/decisions/cite-by-qid.md` §Security).
 *
 * citeproc-php renders CSL-JSON — which contains *user-entered statement
 * values* (titles, publisher names, …) — into HTML. The `html` output path
 * must never let those values escape as markup, so every tag is checked
 * against a small allowlist (`<i> <b> <em> <strong> <span class="…">`); all
 * other tags are dropped (their text content is kept) and every attribute
 * except a whitelisted `class` is removed.
 *
 * Pure PHP (no MediaWiki dependencies) so the XSS-critical logic is
 * unit-testable standalone, following the FragmentSanitizer precedent in
 * EmbeddableContent without coupling the two extensions.
 *
 * @license GPL-2.0-or-later
 */
class CitationSanitizer {

	/** Tags allowed through the sanitizer. */
	private const ALLOWED_TAGS = [ 'i', 'b', 'em', 'strong', 'span' ];

	/** Class names allowed on kept tags (`span class="…"`). */
	private const ALLOWED_CLASS = '/^[a-zA-Z0-9 _-]+$/';

	/**
	 * Sanitizes citation HTML against the allowlist. Disallowed tags are
	 * removed but their text content is kept; allowed tags keep only a
	 * `class` attribute whose value passes the class-name check. Runs of
	 * whitespace are collapsed to single spaces so the stripped structural
	 * markup (citeproc's div wrappers) cannot leak `\n  ` indentation into
	 * the page (a leading space would wrap the citation in a `<pre>` block).
	 */
	public function sanitizeHtml( string $html ): string {
		// HTML comments never belong in citation output.
		$html = preg_replace( '/<!--.*?-->/s', '', $html ) ?? $html;
		$html = preg_replace_callback(
			'#<(/)?([a-zA-Z][a-zA-Z0-9]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>#',
			function ( array $m ): string {
				$closing = $m[1] === '/';
				$tag = strtolower( $m[2] );
				if ( !in_array( $tag, self::ALLOWED_TAGS, true ) ) {
					return '';
				}
				if ( $closing ) {
					return '</' . $tag . '>';
				}
				return '<' . $tag . $this->allowedAttributes( $m[3] ) . '>';
			},
			$html
		) ?? $html;
		return trim( preg_replace( '/\s+/u', ' ', $html ) ?? $html );
	}

	/**
	 * Extracts the attributes of an opening tag, keeping only `class`
	 * values that pass the class-name check.
	 */
	private function allowedAttributes( string $attrText ): string {
		if ( preg_match( '/\sclass\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $attrText, $m ) !== 1 ) {
			return '';
		}
		$value = $m[2] !== '' ? $m[2] : $m[3];
		// Decode entities so the class-name check sees the real value.
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match( self::ALLOWED_CLASS, $value ) !== 1 ) {
			return '';
		}
		return ' class="' . htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) . '"';
	}
}
