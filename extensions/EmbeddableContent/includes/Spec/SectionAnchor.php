<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Derives the automatic link anchor of a heading from the id MediaWiki
 * generates for it.
 *
 * A heading that mixes text and inline math is still a sentence a reader can
 * name: `=== Symmetric groups $\mathcal{S}_{n}$ ===` should be linkable as
 * `#Symmetric_groups`. The generated id keeps the math
 * (`Symmetric_groups_$\mathcal{S}_{n}$`), and `{`/`}` make it unusable in a
 * wikitext fragment, so contributors had to add `{{anchor|…}}` by hand.
 *
 * The contract (pure PHP, unit-tested):
 *  - remove every balanced `$…$` inline-math segment;
 *  - if nothing but separators remains — the heading is math ONLY — keep the
 *    math, because stripping it would leave no anchor at all;
 *  - replace the characters that cannot appear in a wikitext fragment
 *    (`# < > [ ] | { }`) with `_`, collapse runs of `_`, and trim `_` from
 *    the ends.
 *
 * A heading that needs no normalisation returns its own id; callers skip the
 * injection then. An explicit `{{anchor|…}}` always wins over this value.
 *
 * @license GPL-2.0-or-later
 */
final class SectionAnchor {

	/** The characters that cannot appear in a wikitext link fragment. */
	private const ILLEGAL_CHARS = [ '#', '<', '>', '[', ']', '|', '{', '}' ];

	/**
	 * @param string $headingId the heading id as rendered (HTML entities
	 *  already decoded)
	 * @return string the automatic anchor, or '' for an empty id
	 */
	public static function fromHeadingId( string $headingId ): string {
		$text = trim( $headingId );
		if ( $text === '' ) {
			return '';
		}
		$anchor = self::normalize( self::stripMath( $text ) );
		if ( $anchor === '' ) {
			// The heading is math only — stripping it would leave nothing.
			$anchor = self::normalize( $text );
		}
		return $anchor;
	}

	/**
	 * Removes balanced `$…$` segments. An escaped `\$` is a literal dollar
	 * (the math help page's price convention) and never opens a segment.
	 */
	private static function stripMath( string $text ): string {
		$stripped = preg_replace( '/(?<!\\\\)\$[^$]*\$/', '', $text );
		return $stripped ?? $text;
	}

	/** Replaces illegal characters, collapses `_` runs and trims them. */
	private static function normalize( string $text ): string {
		$text = str_replace( self::ILLEGAL_CHARS, '_', $text );
		$text = preg_replace( '/\s+/u', '_', $text ) ?? $text;
		$text = preg_replace( '/_+/u', '_', $text ) ?? $text;
		return trim( $text, '_' );
	}

}
