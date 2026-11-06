<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Pure rules for the automatic thumbnail credit line.
 *
 * The MediaWiki-bound half (Media/ImageCredit) resolves the file's image
 * item and builds the linked HTML parts; this class owns the deterministic
 * formatting and the `noCredit` suppression syntax so both are unit-tested
 * without a parser or a database.
 *
 * `noCredit` is the CANONICAL form as an existing image option —
 * `[[File:X|thumb|Caption|class=noCredit]]` — because any unknown bare
 * option is swallowed by core's image parser (only the last unnamed option
 * survives as the caption). For backwards compatibility a bare `noCredit`
 * that IS the whole caption (`[[File:X|noCredit]]`) is also accepted.
 *
 * @license GPL-2.0-or-later
 */
final class ImageCredit {

	/** The token that suppresses the credit line (case-insensitive). */
	public const TOKEN = 'noCredit';

	/**
	 * Join the non-empty credit parts into the caption line.
	 *
	 * @param string[] $parts already-escaped HTML fragments
	 * @param string $prefix the localized "Image" label ('' = no prefix)
	 * @return string '' when no part is present
	 */
	public static function line( array $parts, string $prefix = '' ): string {
		$kept = [];
		foreach ( $parts as $part ) {
			$part = trim( (string)$part );
			if ( $part !== '' ) {
				$kept[] = $part;
			}
		}
		if ( $kept === [] ) {
			return '';
		}
		$body = implode( ' · ', $kept );
		$prefix = trim( $prefix );
		return $prefix === '' ? $body : $prefix . ': ' . $body;
	}

	/**
	 * The `class` image option's value with a `noCredit` token removed.
	 *
	 * @return array{found:bool,class:string} `class` is trimmed and may be ''
	 */
	public static function stripNoCreditClass( string $class ): array {
		$pattern = '/(?:^|\s)' . preg_quote( self::TOKEN, '/' ) . '(?:\s|$)/i';
		$found = preg_match( $pattern, $class ) === 1;
		if ( !$found ) {
			return [ 'found' => false, 'class' => $class ];
		}
		$stripped = preg_replace( $pattern, ' ', $class );
		return [
			'found' => true,
			'class' => trim( preg_replace( '/\s+/', ' ', (string)$stripped ) ?? '' ),
		];
	}

	/** Whether the caption is exactly the bare `noCredit` flag. */
	public static function isNoCreditCaption( string $caption ): bool {
		return strcasecmp( trim( $caption ), self::TOKEN ) === 0;
	}

}
