<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/**
 * The multi-image editor's record helper (portrait/logo/images on Person,
 * Collective, Software and FOSS items).
 *
 * An entry is `{ fileTitle: 'File:Foo.png' }`. Each entry is written as one
 * `image` statement (the File: page URL); the licence/author facts live on
 * the File page's image item (created by ImageUploadHelper at upload time),
 * not on the entity.
 *
 * @license GPL-2.0-or-later
 */
final class MediaEntries {

	/**
	 * @param mixed $raw a JSON string (hidden form field) or an array
	 * @return list<array{fileTitle:string}>
	 */
	public static function normalize( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( !is_array( $raw ) ) {
			return [];
		}
		$out = [];
		$seen = [];
		foreach ( $raw as $row ) {
			if ( !is_array( $row ) ) {
				continue;
			}
			$fileTitle = trim( (string)( $row['fileTitle'] ?? '' ) );
			if ( $fileTitle === '' ) {
				continue;
			}
			if ( stripos( $fileTitle, 'File:' ) !== 0 ) {
				$fileTitle = 'File:' . $fileTitle;
			}
			// Normalize the title case/underscores for the dedupe key without
			// requiring MediaWiki (normalize() is unit-tested standalone).
			$key = 'file:' . strtolower( str_replace( '_', ' ', substr( $fileTitle, 5 ) ) );
			if ( isset( $seen[$key] ) ) {
				continue;
			}
			$seen[$key] = true;
			$out[] = [ 'fileTitle' => $fileTitle ];
		}
		return $out;
	}

	/**
	 * Validate the entries: every File: page must exist. Returns an error or
	 * null.
	 *
	 * @param list<array{fileTitle:string}> $entries
	 */
	public static function validate( array $entries ): ?string {
		foreach ( $entries as $entry ) {
			$title = self::title( $entry['fileTitle'] );
			if ( $title === null || !$title->exists() ) {
				return "image \"{$entry['fileTitle']}\" does not exist on this wiki.";
			}
		}
		return null;
	}

	/**
	 * @param list<array{fileTitle:string}> $entries
	 * @return string[] File: page full URLs (one image statement each)
	 */
	public static function fileUrls( array $entries ): array {
		$urls = [];
		foreach ( $entries as $entry ) {
			$title = self::title( $entry['fileTitle'] );
			if ( $title !== null ) {
				$urls[] = $title->getFullURL();
			}
		}
		return $urls;
	}

	/**
	 * @return list<array{fileTitle:string}> the item's image entries on the
	 *   given property (in statement order)
	 */
	public static function fromItem( Item $item, string $propertyId ): array {
		try {
			$id = new NumericPropertyId( $propertyId );
		} catch ( \Throwable $e ) {
			return [];
		}
		$out = [];
		foreach ( $item->getStatements()->getByPropertyId( $id ) as $statement ) {
			$snak = $statement->getMainSnak();
			$value = $snak instanceof PropertyValueSnak ? $snak->getDataValue() : null;
			if ( !$value instanceof StringValue ) {
				continue;
			}
			$title = self::titleFromUrl( (string)$value->getValue() );
			if ( $title !== null ) {
				$out[] = [ 'fileTitle' => $title->getPrefixedText() ];
			}
		}
		return $out;
	}

	public static function title( string $value ): ?Title {
		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}
		if ( stripos( $value, 'File:' ) === 0 ) {
			$title = Title::newFromText( $value );
		} else {
			$title = Title::makeTitle( NS_FILE, $value );
		}
		return $title !== null && $title->inNamespace( NS_FILE ) ? $title : null;
	}

	private static function titleFromUrl( string $url ): ?Title {
		$parts = parse_url( $url );
		if ( !is_array( $parts ) ) {
			return null;
		}
		$candidate = '';
		if ( isset( $parts['query'] ) ) {
			parse_str( (string)$parts['query'], $query );
			$candidate = is_string( $query['title'] ?? null ) ? $query['title'] : '';
		}
		if ( $candidate === '' ) {
			$path = (string)( $parts['path'] ?? '' );
			$candidate = $path !== '' ? basename( $path ) : '';
		}
		if ( $candidate === '' || $candidate === '/' ) {
			return null;
		}
		return self::title( rawurldecode( $candidate ) );
	}

}
