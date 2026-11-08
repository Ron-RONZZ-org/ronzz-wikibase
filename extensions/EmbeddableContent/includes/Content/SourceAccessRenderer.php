<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

/**
 * Renders the "Access" infobox cell of a Source: page (multi-access).
 *
 * Pure and MediaWiki-free so the branching logic is unit-testable: the
 * parser-function wrapper (SourceAccess) resolves the page's item, collects
 * the access entries and supplies the localized strings + hrefs; this class
 * only formats them:
 *
 *   * one link per VISIBLE access entry, separated by ` | `;
 *   * URL entries are labelled url1, url2, … in display order;
 *   * file entries are labelled with their extension (pdf, epub, …);
 *   * every link opens in a new tab (`target=_blank`, `rel=noopener`);
 *   * when hidden (login-only) entries exist and the viewer is anonymous,
 *     the "Login to view additional access modes" link is appended;
 *   * no entries → the localized "N/A" text.
 *
 * @license GPL-2.0-or-later
 */
final class SourceAccessRenderer {

	/**
	 * @param list<array{kind:string,href:string,label:string}> $links
	 *   visible entries, in display order
	 * @param string $naText localized "N/A" cell text
	 * @param bool $hasHidden whether login-only entries exist
	 * @param string $loginUrl Special:UserLogin URL ('' = omit)
	 * @param string $loginText localized "Login to view additional access
	 *   modes" text
	 */
	public static function render(
		array $links,
		string $naText,
		bool $hasHidden,
		string $loginUrl,
		string $loginText
	): string {
		if ( $links === [] ) {
			return htmlspecialchars( $naText );
		}
		$parts = [];
		$urlIndex = 0;
		foreach ( $links as $link ) {
			$href = (string)( $link['href'] ?? '' );
			if ( $href === '' ) {
				continue;
			}
			$label = trim( (string)( $link['label'] ?? '' ) );
			if ( $label === '' ) {
				$label = 'url' . ( ++$urlIndex );
			}
			$parts[] = '<a href="' . htmlspecialchars( $href ) . '"'
				. ' target="_blank" rel="noopener noreferrer">'
				. htmlspecialchars( $label ) . '</a>';
		}
		if ( $parts === [] ) {
			return htmlspecialchars( $naText );
		}
		$html = implode( ' | ', $parts );
		if ( $hasHidden && $loginUrl !== '' ) {
			$html .= ' · <a href="' . htmlspecialchars( $loginUrl ) . '"'
				. ' target="_blank" rel="noopener noreferrer">'
				. htmlspecialchars( $loginText ) . '</a>';
		}
		return $html;
	}

}
