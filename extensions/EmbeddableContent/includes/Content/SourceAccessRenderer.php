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
		// File entries are grouped by extension so a multi-format source
		// renders `PDF: en|fr | EPUB: en`; a group with a language-less
		// (legacy) entry falls back to the historical per-entry links.
		$fileGroups = [];
		$order = [];
		foreach ( $links as $link ) {
			$href = (string)( $link['href'] ?? '' );
			if ( $href === '' ) {
				continue;
			}
			$label = trim( (string)( $link['label'] ?? '' ) );
			$language = strtolower( trim( (string)( $link['language'] ?? '' ) ) );
			if ( ( $link['kind'] ?? '' ) !== 'file' ) {
				$urlIndex += 1;
				$name = $label !== '' ? $label : 'url' . $urlIndex;
				$parts[] = self::urlPart( $href, $name, $language );
				continue;
			}
			if ( $label === '' ) {
				$label = 'file';
			}
			$key = 'file:' . $label;
			if ( !isset( $fileGroups[$key] ) ) {
				$fileGroups[$key] = [];
				$order[] = $key;
			}
			$fileGroups[$key][] = [
				'href' => $href,
				'label' => $label,
				'language' => $language,
			];
		}
		foreach ( $order as $key ) {
			$group = $fileGroups[$key];
			$allHaveLanguage = true;
			foreach ( $group as $entry ) {
				if ( $entry['language'] === '' ) {
					$allHaveLanguage = false;
					break;
				}
			}
			$label = $group[0]['label'];
			if ( $allHaveLanguage ) {
				$languageLinks = [];
				$seen = [];
				foreach ( $group as $entry ) {
					$lang = $entry['language'];
					if ( $lang === '' || isset( $seen[$lang] ) ) {
						continue;
					}
					$seen[$lang] = true;
					$languageLinks[] = self::link( $entry['href'], $lang );
				}
				$parts[] = htmlspecialchars( strtoupper( $label ) ) . ': '
					. implode( '|', $languageLinks );
				continue;
			}
			foreach ( $group as $entry ) {
				$parts[] = self::urlPart( $entry['href'], $entry['label'], '' );
			}
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

	/** A URL entry: `url1: <a>en</a>` (or a plain `url1` link). */
	private static function urlPart( string $href, string $label, string $language ): string {
		if ( $language === '' ) {
			return self::link( $href, $label );
		}
		return htmlspecialchars( $label ) . ': ' . self::link( $href, $language );
	}

	private static function link( string $href, string $text ): string {
		return '<a href="' . htmlspecialchars( $href ) . '"'
			. ' target="_blank" rel="noopener noreferrer">'
			. htmlspecialchars( $text ) . '</a>';
	}

}
