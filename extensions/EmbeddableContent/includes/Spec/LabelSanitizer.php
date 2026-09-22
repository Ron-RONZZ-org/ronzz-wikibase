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

	/**
	 * Normalizes a label into a usable MediaWiki page title. In addition to
	 * stripMarkup, the characters MediaWiki forbids in titles
	 * (`# < > [ ] { } |` — Title::isValid()) are replaced with a dash, so a
	 * label like "C# programming language" or "A|B" still yields a page
	 * instead of the "label cannot be used as a page title" warning.
	 *
	 * The item LABEL is never changed — only the derived page title — so
	 * the entity keeps its exact term and the classic page is sitelinked to
	 * it. A label that normalizes to an empty string is still unusable (the
	 * caller keeps the item-only fallback).
	 *
	 * `/` is left alone: MediaWiki accepts it (it only makes the title a
	 * subpage). Repeated dashes and surrounding whitespace collapse; leading
	 * and trailing dashes are trimmed.
	 *
	 * @param string $text a label/title candidate (harvested or hand-typed)
	 * @return string a title-safe form of the text
	 */
	public static function normalizeForTitle( string $text ): string {
		$text = self::stripMarkup( $text );
		$text = preg_replace( '/[#<>\[\]{}|]/', '-', $text ) ?? $text;
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		$text = preg_replace( '/-{2,}/', '-', $text ) ?? $text;
		return trim( $text, " -\t\n\r\0\x0B" );
	}

}
