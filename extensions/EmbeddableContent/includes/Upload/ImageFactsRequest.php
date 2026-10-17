<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Upload;

/**
 * Pure request-shape helpers for the image-facts API module.
 *
 * The API and Special:Upload share ImageItemCreator for the entity work;
 * these helpers only normalise the caller's file title and validate the
 * license item id, so they can be unit-tested without MediaWiki.
 *
 * @license GPL-2.0-or-later
 */
final class ImageFactsRequest {

	/**
	 * Normalises a file title as an API client may send it: an optional
	 * case-insensitive "File:" prefix, underscores instead of spaces, and
	 * repeated whitespace. Returns null for an empty result.
	 */
	public static function normalizeFileName( string $input ): ?string {
		$name = preg_replace( '/^file\s*:\s*/i', '', trim( $input ) );
		if ( $name === null ) {
			return null;
		}
		$name = str_replace( '_', ' ', $name );
		$name = preg_replace( '/\s+/', ' ', trim( $name ) );
		if ( $name === null || $name === '' ) {
			return null;
		}
		return trim( $name );
	}

	/** A license item reference is a Q-id; labels are resolved by the caller. */
	public static function isValidLicenseId( string $id ): bool {
		return preg_match( '/^Q[1-9]\d*$/', $id ) === 1;
	}
}
