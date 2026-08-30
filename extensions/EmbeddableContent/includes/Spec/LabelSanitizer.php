<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Sanitizes label/title text before it becomes an item label or a
 * classic-page title. External authorities (notably OpenAlex work titles)
 * carry HTML markup — taxonomic terms wrapped in <i>…</i> — which Wikibase
 * stores verbatim in the label term and MediaWiki rejects in page titles
 * (Title::isValid() → the Add* classic-page creation was silently skipped;
 * see the "Planck 2018 results" item Q1232).
 *
 * The pipeline, in order:
 *  1. decode HTML entities (so "&lt;i&gt;…" cannot survive tag-stripping as
 *     literal markup),
 *  2. remove all tags,
 *  3. collapse whitespace runs (tag boundaries leave stray spaces, e.g.
 *     "</i>  next" → "next"),
 *  4. trim.
 *
 * Pure static — no MediaWiki runtime, unit-tested.
 *
 * @license GPL-2.0-or-later
 */
final class LabelSanitizer {

	/**
	 * @param string $text a label/title candidate (harvested or hand-typed)
	 * @return string the same text without markup, ready for a term or title
	 */
	public static function stripMarkup( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/<[^>]*>/', '', $text ) ?? $text;
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		return trim( $text );
	}

}
