<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Normalizes the `accessFile` request field of action=addsource — the
 * append-only way for a machine client to attach ONE File: page as an access
 * entry of a source.
 *
 * The field is a JSON object (the API param is a string; the flow service also
 * accepts a decoded array):
 *
 *   { "fileTitle": "Foo.pdf", "internal": true, "license": "Q…", "language": "en" }
 *
 * `internal` (the default when omitted is PUBLIC only when a licence is
 * supplied) gates the bytes to logged-in readers; the licence is forced to the
 * seeded "All rights reserved" item by AccessEntries::resolveFileItems, so a
 * supplied licence is dropped for an internal entry. A public entry requires an
 * explicit licence item.
 *
 * This normalizer is PURE: the semantic checks (the File: page exists, the
 * licence item exists) stay in AccessEntries::validate, which is MW-bound.
 *
 * @license GPL-2.0-or-later
 */
final class AccessFileRequest {

	/**
	 * @param mixed $value a decoded array, or the JSON object string the API
	 *   module receives
	 * @return array<string,string>|string the normalized access entry, or a
	 *   user-facing error
	 */
	public static function normalize( $value ) {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}
		if ( !is_array( $value ) ) {
			return 'accessFile must be a JSON object with at least a fileTitle.';
		}
		$fileTitle = trim( (string)( $value['fileTitle'] ?? '' ) );
		if ( $fileTitle === '' ) {
			return 'accessFile needs a fileTitle (a File: page title or DB key).';
		}

		// A user-supplied copy of a published work is internal unless the
		// caller declares it public AND names the licence.
		$internal = self::isTrue( $value['internal'] ?? false );
		$entry = [
			'type' => 'file',
			'fileTitle' => $fileTitle,
			'public' => $internal ? '' : '1',
		];
		if ( !$internal ) {
			$license = strtoupper( trim( (string)( $value['license'] ?? '' ) ) );
			if ( preg_match( '/^Q[1-9]\d*$/', $license ) !== 1 ) {
				return 'a public accessFile needs a license item id (Q…).';
			}
			$entry['license'] = $license;
		}
		$language = trim( (string)( $value['language'] ?? '' ) );
		if ( $language !== '' ) {
			$entry['language'] = $language;
		}
		return $entry;
	}

	/** @param mixed $value */
	private static function isTrue( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return $value !== 0;
		}
		return in_array( strtolower( trim( (string)$value ) ), [ '1', 'true', 'yes', 'on' ], true );
	}
}
