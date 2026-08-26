<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Pure parser for the Commons `imageinfo` payload (`prop=imageinfo&
 * iiprop=extmetadata|size`), extracting the fields that auto-fill the upload
 * form. No I/O — unit-testable with a canned JSON array.
 *
 * The extmetadata values are HTML fragments (the Artist field can carry
 * links, the description can contain markup); they are stripped and capped
 * before they ever reach a form field.
 *
 * @license GPL-2.0-or-later
 */
final class CommonsMetadataParser {

	/** Cap on short extracted text fields (name/author/license/credit). */
	private const TEXT_CAP = 250;

	/**
	 * Cap on the fetched description — matches the instance's raised term
	 * limit (string-limits multilang length 2000, wbt_text VARBINARY(2000)).
	 */
	private const DESCRIPTION_CAP = 2000;

	/**
	 * @param array<string,mixed> $imageInfo one `imageinfo` row
	 */
	public static function fromImageInfo( array $imageInfo ): ImageMetadata {
		$ext = $imageInfo['extmetadata'] ?? [];
		$name = self::normalizeDestName(
			self::textField( $ext, 'ObjectName' ) ?? self::textField( $ext, 'ImageDescription' )
		);
		// The ObjectName usually carries no extension — append the canonical
		// one from the file's MIME type so the dest-name field is complete
		// (mirrors the JS extensionForMime in resources/uploadmeta.js).
		if ( $name !== null && strpos( $name, '.' ) === false ) {
			$mimeExt = self::extensionForMime( (string)( $imageInfo['mime'] ?? '' ) );
			if ( $mimeExt !== '' ) {
				$name .= '.' . $mimeExt;
			}
		}
		return new ImageMetadata(
			name: $name,
			description: self::textField( $ext, 'ImageDescription', self::DESCRIPTION_CAP ),
			author: self::textField( $ext, 'Artist' ),
			licenseLabel: self::textField( $ext, 'LicenseShortName' ),
			credit: self::textField( $ext, 'Credit' ),
			width: isset( $imageInfo['width'] ) ? (int)$imageInfo['width'] : null,
			height: isset( $imageInfo['height'] ) ? (int)$imageInfo['height'] : null,
			fileSize: isset( $imageInfo['size'] ) ? (int)$imageInfo['size'] : null,
			mime: isset( $imageInfo['mime'] ) ? (string)$imageInfo['mime'] : null,
			thumbUrl: isset( $imageInfo['thumburl'] ) ? (string)$imageInfo['thumburl'] : null,
			sourceUrl: (string)( $imageInfo['descriptionurl'] ?? '' ),
			warnings: []
		);
	}

	/**
	 * Extracts one `extmetadata` value: HTML-stripped, entity-decoded,
	 * whitespace-collapsed and length-capped (at a sentence boundary when the
	 * cap is exceeded — never mid-sentence). Null when absent/empty.
	 *
	 * @param array<string,mixed> $ext metadata the `extmetadata` map
	 */
	private static function textField( array $ext, string $key, int $cap = self::TEXT_CAP ): ?string {
		$value = $ext[$key]['value'] ?? null;
		if ( !is_string( $value ) || $value === '' ) {
			return null;
		}
		$text = html_entity_decode(
			strip_tags( $value ),
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);
		$text = trim( (string)preg_replace( '/\s+/u', ' ', $text ) );
		if ( $text === '' ) {
			return null;
		}
		return self::truncateAtSentenceBoundary( $text, $cap );
	}

	/**
	 * Length-caps a text field at $cap, cutting at the last sentence-ending
	 * punctuation (". ", "! ", "? ") inside the cap when one exists past the
	 * first 100 chars — a fetched summary never ends mid-sentence. Hard cut
	 * when the text has no earlier sentence boundary.
	 */
	private static function truncateAtSentenceBoundary( string $text, int $cap ): string {
		if ( mb_strlen( $text ) <= $cap ) {
			return $text;
		}
		$slice = mb_substr( $text, 0, $cap );
		$last = -1;
		foreach ( [ '. ', '! ', '? ' ] as $sep ) {
			$pos = mb_strrpos( $slice, $sep );
			if ( $pos !== false && $pos > $last ) {
				$last = $pos;
			}
		}
		if ( $last >= 100 ) {
			return mb_substr( $slice, 0, $last + 1 );
		}
		return $slice;
	}

	/**
	 * Destination-file-name normalization for the fetched ObjectName:
	 * lowercase, any word separator (spaces, underscores, camelCase/
	 * PascalCase boundaries, existing dashes) → single dashes, and
	 * MediaWiki-illegal filename characters (`#<>[]|{}:`) dropped; a
	 * trailing extension is preserved (lowercased). Unicode-aware
	 * (`\p{L}\p{N}`) so accented names like "École" survive. Mirrors the JS
	 * normalizeDestName in resources/uploadmeta.js and the cleaning in
	 * ImageUploadHelper::destName. Null when nothing usable remains.
	 */
	public static function normalizeDestName( ?string $name ): ?string {
		if ( $name === null ) {
			return null;
		}
		$name = trim( $name );
		if ( $name === '' ) {
			return null;
		}
		$ext = (string)pathinfo( $name, PATHINFO_EXTENSION );
		$base = $ext === '' ? $name : substr( $name, 0, -( strlen( $ext ) + 1 ) );
		// camelCase / PascalCase boundaries first ("nationalGeographic" →
		// "national Geographic") — must run before lowercasing.
		$base = (string)preg_replace( '/([\p{L}\p{N}])([\p{Lu}])/u', '$1 $2', $base );
		$base = mb_strtolower( $base, 'UTF-8' );
		// Any run of non-letter/digit (space, underscore, dot, dash, illegal
		// chars, …) is one word separator → a single dash.
		$base = (string)preg_replace( '/[^\p{L}\p{N}]+/u', '-', $base );
		$base = trim( $base, '-' );
		if ( $base === '' ) {
			return null;
		}
		return $ext === '' ? $base : $base . '.' . strtolower( $ext );
	}

	/**
	 * Canonical lowercase extension for a MIME type ('' when unknown).
	 * Mirrors the JS extensionForMime in resources/uploadmeta.js.
	 */
	public static function extensionForMime( string $mime ): string {
		$mime = strtolower( trim( explode( ';', $mime )[0] ) );
		$map = [
			'image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png',
			'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg',
			'image/tiff' => 'tiff', 'image/x-tiff' => 'tiff',
			'application/pdf' => 'pdf', 'application/epub+zip' => 'epub',
			'application/djvu' => 'djvu',
			'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogv',
			'video/quicktime' => 'mov',
			'audio/mpeg' => 'mp3', 'audio/ogg' => 'oga', 'audio/wav' => 'wav',
			'audio/x-wav' => 'wav', 'audio/x-m4a' => 'm4a', 'audio/mp4' => 'm4a',
			'audio/flac' => 'flac', 'audio/opus' => 'opus',
		];
		return $map[$mime] ?? '';
	}
}
