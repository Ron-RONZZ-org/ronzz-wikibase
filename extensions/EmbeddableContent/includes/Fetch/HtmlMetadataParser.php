<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Pure HTML metadata extraction for the website/webpage URL-first flow.
 *
 * No DOM dependency: regex over the common <head> markers a webmaster or a
 * CMS actually emits — <title>, og:title, og:description, meta description,
 * meta keywords — plus the first <p> paragraph as the short intro. Values
 * are HTML-entity-decoded, whitespace-collapsed and length-capped so a
 * hostile page can never smuggle a huge payload into the review form.
 *
 * @license GPL-2.0-or-later
 */
final class HtmlMetadataParser {

	public const MAX_TITLE = 250;
	public const MAX_DESCRIPTION = 500;
	public const MAX_INTRO = 1000;
	public const MAX_KEYWORDS = 500;

	public static function extract( string $html ): PageMetadata {
		$title = self::clean(
			self::metaContent( $html, [ 'og:title' ] ),
			self::MAX_TITLE
		);
		if ( $title === '' && preg_match( '/<title[^>]*>([^<]+)<\/title>/is', $html, $m ) === 1 ) {
			$title = self::clean( $m[1], self::MAX_TITLE );
		}

		$description = self::clean(
			self::metaContent( $html, [ 'og:description', 'description' ] ),
			self::MAX_DESCRIPTION
		);

		$keywords = self::clean(
			self::metaContent( $html, [ 'keywords' ] ),
			self::MAX_KEYWORDS
		);

		$intro = '';
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $html, $m ) === 1 ) {
			$intro = self::clean( strip_tags( $m[1] ), self::MAX_INTRO );
		}

		return new PageMetadata( $title, $description, $intro, $keywords );
	}

	/**
	 * Content of the first <meta> tag whose name OR property attribute is one
	 * of $keys (order-insensitive attribute order), '' when none matches.
	 *
	 * @param string[] $keys
	 */
	private static function metaContent( string $html, array $keys ): string {
		if ( preg_match_all( '/<meta\b[^>]*>/i', $html, $tags ) === false ) {
			return '';
		}
		foreach ( $tags[0] as $tag ) {
			$name = '';
			if ( preg_match( '/\b(?:name|property)=["\']([^"\']+)["\']/i', $tag, $m ) === 1 ) {
				$name = strtolower( $m[1] );
			}
			if ( !in_array( $name, $keys, true ) ) {
				continue;
			}
			if ( preg_match( '/\bcontent=["\']([^"\']*)["\']/i', $tag, $m ) === 1 ) {
				return $m[1];
			}
		}
		return '';
	}

	/** Decodes entities, collapses whitespace, caps length. */
	private static function clean( string $value, int $max ): string {
		$value = html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = trim( (string)preg_replace( '/\s+/u', ' ', $value ) );
		return mb_strlen( $value ) > $max ? mb_substr( $value, 0, $max - 1 ) . '…' : $value;
	}
}
