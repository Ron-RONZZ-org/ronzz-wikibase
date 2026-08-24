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

	/** Cap on any extracted text field (form maxlengths / term limits). */
	private const TEXT_CAP = 250;

	/**
	 * @param array<string,mixed> $imageInfo one `imageinfo` row
	 */
	public static function fromImageInfo( array $imageInfo ): ImageMetadata {
		$ext = $imageInfo['extmetadata'] ?? [];
		return new ImageMetadata(
			name: self::textField( $ext, 'ObjectName' ) ?? self::textField( $ext, 'ImageDescription' ),
			description: self::textField( $ext, 'ImageDescription' ),
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
	 * whitespace-collapsed and length-capped. Null when absent/empty.
	 *
	 * @param array<string,mixed> $ext metadata the `extmetadata` map
	 */
	private static function textField( array $ext, string $key ): ?string {
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
		return mb_substr( $text, 0, self::TEXT_CAP );
	}
}
