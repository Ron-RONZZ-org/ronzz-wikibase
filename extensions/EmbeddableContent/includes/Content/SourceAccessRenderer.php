<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

/**
 * Renders the "Access" infobox cell of a Source: page (issue follow-up).
 *
 * Pure and MediaWiki-free so the branching logic is unit-testable: the
 * parser-function wrapper (SourceAccess) resolves the page's item, collects
 * the statement values and supplies the localized "N/A" text; this class
 * only decides what the cell shows:
 *
 *   1. a copy stored on this wiki (the `file` property)  → the file name,
 *      linked to Special:SourceFile (which hosts the PDF preview, the
 *      licence and the gated download);
 *   2. else a non-direct access URL (the `access URL` property) → a
 *      clickable external link;
 *   3. else → "N/A" (no access fact recorded).
 *
 * @license GPL-2.0-or-later
 */
final class SourceAccessRenderer {

	/**
	 * @param string[] $fileTitles validated File: titles (DBkey form, e.g.
	 *   "File:War and Peace.pdf"), one per `file` statement
	 * @param string[] $accessUrls non-direct access URLs (url datatype), one
	 *   per `access URL` statement
	 * @param string $itemId the sitelinked item id (Q-id, for the special
	 *   file page's licence lookup)
	 * @param string $naText localized "N/A" cell text
	 * @return string wikitext for the access cell (parsed by the template)
	 */
	public static function render( array $fileTitles, array $accessUrls, string $itemId, string $naText ): string {
		// Defensive: the wrapper validates, but empty values from the store
		// must not render a link to nothing.
		$fileTitles = array_values( array_filter( $fileTitles, static fn ( $t ) => $t !== '' ) );
		$accessUrls = array_values( array_filter( $accessUrls, static fn ( $u ) => $u !== '' ) );
		if ( $fileTitles !== [] ) {
			$title = self::plainText( (string)reset( $fileTitles ) );
			$target = 'Special:SourceFile?item=' . rawurlencode( $itemId )
				. '&file=' . rawurlencode( (string)reset( $fileTitles ) );
			return "[[" . $target . "|" . $title . "]]";
		}
		if ( $accessUrls !== [] ) {
			$url = self::linkSafe( (string)reset( $accessUrls ) );
			return "[" . $url . " " . $url . "]";
		}
		return $naText;
	}

	/**
	 * Wikitext-safe label for a File: title: MediaWiki titles cannot contain
	 * [ ] | { } < > # — but the value arrives from a DB statement, so strip
	 * defensively rather than trust the store.
	 */
	private static function plainText( string $value ): string {
		return (string)preg_replace( '/[\[\]|{}<>]/', '', $value );
	}

	/**
	 * External-link-safe URL: the `access URL` value was URL-validated at
	 * creation (FragmentSanitizer), but a url datatype can still carry
	 * characters that break the [url label] syntax — encode spaces and the
	 * closing bracket so the link always parses.
	 */
	private static function linkSafe( string $url ): string {
		return str_replace( [ ' ', ']' ], [ '%20', '%5D' ], $url );
	}
}
