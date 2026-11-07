<?php

declare( strict_types = 1 );

namespace EmbeddableContent\SourceArchive;

/**
 * Reduces converted wikitext to safe plain-ish text:
 *
 *  - `{{…}}` template / parser-function invocations are removed entirely
 *    (they have no "text" to keep, and would execute on render);
 *  - `[[…]]` links are unwrapped to their label;
 *  - `[[File:…]]` / `[[Image:…]]` (and direct image-URL targets) become the
 *    media note plus the caption:
 *      `([<source-url> Media content: view on original URL]) Caption`
 *  - behaviour switches (`__TOC__`, `__NOINDEX__`, …) are removed.
 *
 * `<ref>…</ref>` is deliberately KEPT — citations are part of the archived
 * text.
 *
 * Pure PHP, so the whole scrub is unit-tested without MediaWiki.
 *
 * @license GPL-2.0-or-later
 */
final class WikitextScrubber {

	/** Link options that are NOT a caption when they are the last part. */
	private const MEDIA_OPTIONS = '/^(?:thumb|thumbnail|frameless|upright|border|left|right|center|centre|none|\d+px|alt=.*|link=.*|class=.*|lang=.*|page=.*)$/i';

	/**
	 * @param string $sourceUrl the page URL (for the media note link)
	 * @param string $mediaNote the note label, e.g.
	 *   "Media content: view on original URL"
	 */
	public function scrub( string $wikitext, string $sourceUrl, string $mediaNote ): string {
		$text = $this->stripTemplates( $wikitext );
		$text = $this->unwrapLinks( $text, $sourceUrl, $mediaNote );
		$text = (string)preg_replace( '/__[A-Z]+__/', '', $text );
		$text = (string)preg_replace( '/[ \t]{2,}/', ' ', $text );
		$text = (string)preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text ) === '' ? '' : trim( $text ) . "\n";
	}

	/**
	 * The media note as wikitext: an external link labelled with the note.
	 * Public because the converter's image placeholders use the same shape.
	 */
	public function mediaNoteFor( string $sourceUrl, string $mediaNote ): string {
		$note = $mediaNote !== '' ? $mediaNote : 'Media content';
		if ( $sourceUrl === '' ) {
			return '(' . $note . ')';
		}
		$url = str_replace( [ ' ', ']' ], [ '%20', '%5D' ], $sourceUrl );
		return '([' . $url . ' ' . $note . '])';
	}

	/** Removes innermost `{{…}}` first, so nested invocations unwind fully. */
	private function stripTemplates( string $text ): string {
		while ( preg_match( '/\{\{[^{}]*\}\}/', $text ) ) {
			$text = (string)preg_replace( '/\{\{[^{}]*\}\}/', '', $text );
		}
		return $text;
	}

	private function unwrapLinks( string $text, string $sourceUrl, string $mediaNote ): string {
		return (string)preg_replace_callback(
			'/\[\[([^\[\]]*?)\]\]/',
			function ( array $match ) use ( $sourceUrl, $mediaNote ): string {
				$parts = explode( '|', $match[1] );
				$target = ltrim( trim( $parts[0] ), ':' );
				if ( self::isMedia( $target ) ) {
					$caption = $this->mediaCaption( $parts );
					return $this->mediaNoteFor( $sourceUrl, $mediaNote )
						. ( $caption !== '' ? ' ' . $caption : '' );
				}
				return count( $parts ) > 1 ? trim( (string)end( $parts ) ) : $target;
			},
			$text
		);
	}

	private static function isMedia( string $target ): bool {
		if ( preg_match( '/^(?:file|image)\s*:/i', $target ) ) {
			return true;
		}
		return (bool)preg_match( '#^https?://\S+\.(?:png|jpe?g|gif|webp|svg)(?:\?\S*)?$#i', $target );
	}

	/** @param string[] $parts */
	private function mediaCaption( array $parts ): string {
		$last = trim( (string)end( $parts ) );
		if ( count( $parts ) < 2 || preg_match( self::MEDIA_OPTIONS, $last ) ) {
			return '';
		}
		return $last;
	}
}
