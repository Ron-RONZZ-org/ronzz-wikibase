<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Pure builder for the "Open in a new tab" fragment used by the media hooks
 * and the content-page enhancer (resources/mediaopen.js renders the same
 * markup client-side).
 *
 * The link is `noprint`: the printed page keeps the media and its credit, not
 * the navigation chrome. It always opens the RAW media URL in a new tab, so
 * the reader reaches the browser viewer/player directly.
 *
 * @license GPL-2.0-or-later
 */
final class MediaOpenLink {

	/** The CSS classes shared with resources/fileviewer.css. */
	public const CONTAINER_CLASS = 'wb-media-open noprint';
	public const LINK_CLASS = 'wb-media-open-link';

	/**
	 * @param string $url raw media URL (attribute-escaped here)
	 * @param string $label localized link text (escaped here)
	 */
	public static function html( string $url, string $label ): string {
		return '<span class="' . self::CONTAINER_CLASS . '"> · <a'
			. ' href="' . htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"'
			. ' target="_blank" rel="noopener noreferrer"'
			. ' class="' . self::LINK_CLASS . '">'
			. htmlspecialchars( $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' )
			. '</a></span>';
	}

}
