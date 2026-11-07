<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\EntityClassFilter;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * Derives the "Family1-Family2 (Year)" caption used by the Source:
 * author-year internal mention.
 *
 * The wikitext stores the stable marker `[[Source:X|name-author]]`; the
 * parser calls this helper at render time, so a later author or year
 * correction is reflected without editing every page that mentions the
 * source. The item is registered as a parser-cache dependency by the hook.
 *
 * @license GPL-2.0-or-later
 */
final class AuthorYearMention {

	/**
	 * @return string|null the caption, or null when the item has no author/year
	 */
	public static function caption( EmbeddableContentConfig $config, string $itemId ): ?string {
		if ( preg_match( '/^Q[1-9]\d*$/', $itemId ) !== 1 ) {
			return null;
		}
		$item = self::loadItem( $itemId );
		if ( !$item instanceof Item ) {
			return null;
		}
		$families = self::authorFamilies( $config, $item );
		$year = self::year( $config, $item );
		if ( $families === [] || $year === null ) {
			return null;
		}
		return implode( '-', $families ) . ' (' . $year . ')';
	}

	/**
	 * @return string[]
	 */
	private static function authorFamilies( EmbeddableContentConfig $config, Item $item ): array {
		$propertyId = $config->provenancePropertyIds()['attributedTo'] ?? null;
		if ( $propertyId === null ) {
			return [];
		}
		$personClass = $config->agentClasses()['person'] ?? null;
		$familyProperty = $config->citationMetadataPropertyIds()['familyName'] ?? null;
		$families = [];
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak ) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( !$value instanceof \Wikibase\DataModel\Entity\EntityIdValue ) {
				continue;
			}
			$authorId = $value->getEntityId()->getSerialization();
			$author = self::loadItem( $authorId );
			if ( !$author instanceof Item ) {
				continue;
			}
			$label = EntityLabelText::of( $author ) ?? $authorId;
			$isPerson = $personClass === null || EntityClassFilter::hasAnyClass(
				$author,
				[ $personClass ],
				$config->instanceOfPropertyId()
			);
			$family = $familyProperty !== null
				? self::firstString( $author, $familyProperty )
				: '';
			if ( $family === '' ) {
				$family = $isPerson
					? NameSplitter::splitFullName( $label )['familyName']
					: $label;
			}
			$family = trim( $family );
			if ( $family !== '' ) {
				$families[] = $family;
			}
		}
		return array_values( array_unique( $families ) );
	}

	private static function year( EmbeddableContentConfig $config, Item $item ): ?string {
		$propertyId = $config->provenancePropertyIds()['date'] ?? null;
		if ( $propertyId === null ) {
			return null;
		}
		$best = null;
		$bestTimestamp = null;
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak || !$snak->getDataValue() instanceof TimeValue ) {
				continue;
			}
			$time = $snak->getDataValue();
			$timestamp = self::timestamp( $time );
			if ( $bestTimestamp === null || $timestamp > $bestTimestamp ) {
				$bestTimestamp = $timestamp;
				$best = $time;
			}
		}
		if ( !$best instanceof TimeValue ) {
			return null;
		}
		// TimeValue has no getYear() in every data-values version; parse the
		// signed ISO day (WikibaseTime::isoDay normalizes the 00 placeholders).
		$iso = WikibaseTime::isoDay( $best->getTime() );
		if ( $iso === null || preg_match( '/^(-?)(\d{4})-\d{2}-\d{2}$/', $iso, $m ) !== 1 ) {
			return null;
		}
		$year = (int)( $m[1] . $m[2] );
		return $year === 0 ? null : (string)$year;
	}

	private static function timestamp( TimeValue $time ): string {
		try {
			return (string)$time->getTimestamp();
		} catch ( \Throwable $e ) {
			return $time->getTime();
		}
	}

	private static function firstString( Item $item, string $propertyId ): string {
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof StringValue ) {
				return (string)$snak->getDataValue()->getValue();
			}
		}
		return '';
	}

	private static function loadItem( string $itemId ): ?Item {
		if ( preg_match( '/^Q[1-9]\d*$/', $itemId ) !== 1 ) {
			return null;
		}
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $entity instanceof Item ? $entity : null;
	}

}
