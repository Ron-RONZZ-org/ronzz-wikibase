<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

/**
 * Wraps a rendered citation in a link to its source item's classic wiki page
 * (2026-09 UX batch).
 *
 * Pure HTML assembly: the citeproc output is sanitized (CitationSanitizer,
 * an allowlist that drops `<a>`) BEFORE this runs, so the citation text
 * carries no anchors and the wrapper cannot nest them.
 *
 * @license GPL-2.0-or-later
 */
final class SourceLink {

	/**
	 * @param string $html already-sanitized citation HTML
	 * @param string|null $url the source page URL, or null/empty for no link
	 * @return string the citation, wrapped in an anchor when a URL is given
	 */
	public static function wrap( string $html, ?string $url ): string {
		$url = $url !== null ? trim( $url ) : '';
		if ( $url === '' || $html === '' ) {
			return $html;
		}
		return '<a class="wikibasecitation-source-link" href="'
			. htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '">' . $html . '</a>';
	}
}
