<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Upload;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * Read side of the image-item facts: the values Special:UpdateFile prefills
 * its form with, and the values Special:Upload prefills when the destination
 * is an existing file (the re-upload page).
 *
 * The write side stays ImageItemCreator. This class only reads statements;
 * a missing vocabulary key or a read failure degrades to an empty record, so
 * a prefilled form never blocks an upload.
 *
 * @license GPL-2.0-or-later
 */
final class ImageFactsRecord {

	/**
	 * The writable fact fields of an item, as form values.
	 *
	 * @return array{
	 *   description:string,
	 *   license:string,
	 *   author:string,
	 *   licenseInfo:string,
	 *   sourceText:string,
	 *   sourceUrl:string,
	 *   authorEntity:string,
	 *   sourceEntity:string,
	 *   rightsBasis:string
	 * }
	 */
	public static function fromItem( EmbeddableContentConfig $config, Item $item ): array {
		$props = $config->imagePropertyIds();
		$provenance = $config->provenancePropertyIds();
		return [
			'description' => self::firstDescription( $item ),
			'license' => self::firstEntityId( $item, $props['license'] ?? null ),
			'author' => self::firstString( $item, $props['imageAuthor'] ?? null ),
			'licenseInfo' => self::firstString( $item, $props['imageLicenseInfo'] ?? null ),
			'sourceText' => self::firstString( $item, $props['imageSource'] ?? null ),
			'sourceUrl' => self::firstString( $item, $provenance['sourceUrl'] ?? null ),
			'authorEntity' => implode(
				', ',
				self::entityIds( $item, $provenance['attributedTo'] ?? null )
			),
			'sourceEntity' => self::firstEntityId( $item, $provenance['source'] ?? null ),
			'rightsBasis' => self::firstEntityId( $item, $props['rightsBasis'] ?? null ),
		];
	}

	/**
	 * The record of the item sitelinked to a File: page, or null when the
	 * page is unconnected / the item cannot be read.
	 *
	 * @return array<string,string>|null
	 */
	public static function forFileTitle( EmbeddableContentConfig $config, Title $title ): ?array {
		if ( $title->getNamespace() !== NS_FILE || !$title->exists() ) {
			return null;
		}
		try {
			$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( 'wikibase', $title->getPrefixedText() );
		} catch ( \Throwable $e ) {
			wfLogWarning( 'EmbeddableContent: image-facts sitelink lookup failed for '
				. $title->getPrefixedText() . ': ' . $e->getMessage() );
			return null;
		}
		if ( $itemId === null ) {
			return null;
		}
		$item = self::loadItem( $itemId );
		return $item === null ? null : self::fromItem( $config, $item );
	}

	private static function loadItem( ItemId $itemId ): ?Item {
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $entity instanceof Item ? $entity : null;
	}

	private static function firstString( Item $item, ?string $propertyId ): string {
		if ( $propertyId === null || $propertyId === '' ) {
			return '';
		}
		foreach ( self::statements( $item, $propertyId ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof StringValue ) {
				return (string)$snak->getDataValue()->getValue();
			}
		}
		return '';
	}

	private static function firstEntityId( Item $item, ?string $propertyId ): string {
		if ( $propertyId === null || $propertyId === '' ) {
			return '';
		}
		foreach ( self::statements( $item, $propertyId ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof EntityIdValue ) {
				return $snak->getDataValue()->getEntityId()->getSerialization();
			}
		}
		return '';
	}

	/** @return string[] */
	private static function entityIds( Item $item, ?string $propertyId ): array {
		if ( $propertyId === null || $propertyId === '' ) {
			return [];
		}
		$ids = [];
		foreach ( self::statements( $item, $propertyId ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof EntityIdValue ) {
				$id = $snak->getDataValue()->getEntityId()->getSerialization();
				if ( !in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	private static function statements( Item $item, string $propertyId ): iterable {
		return $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) );
	}

	private static function firstDescription( Item $item ): string {
		$descriptions = $item->getDescriptions()->toTextArray();
		return (string)( $descriptions['en'] ?? ( $descriptions ? reset( $descriptions ) : '' ) );
	}

}
