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

	/** The fact fields mode=update may clear. */
	public const CLEARABLE_FIELDS = [ 'license', 'author', 'licenseInfo', 'sourceUrl' ];

	/**
	 * Parses the API's comma-separated clear list. Returns the trimmed,
	 * de-duplicated tokens; the caller validates each against
	 * CLEARABLE_FIELDS with isClearableField().
	 *
	 * @return string[]
	 */
	public static function parseClearFields( string $value ): array {
		$fields = [];
		foreach ( explode( ',', $value ) as $token ) {
			$token = trim( $token );
			if ( $token !== '' ) {
				$fields[] = $token;
			}
		}
		return array_values( array_unique( $fields ) );
	}

	public static function isClearableField( string $field ): bool {
		return in_array( $field, self::CLEARABLE_FIELDS, true );
	}
}
