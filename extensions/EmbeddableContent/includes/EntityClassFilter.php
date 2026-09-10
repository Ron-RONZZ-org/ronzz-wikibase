<?php

declare( strict_types = 1 );

namespace EmbeddableContent;

use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\PropertyId;

/**
 * Pure `instance of` helpers, shared by every place that filters items by
 * class (the class-scoped entitysearch API, the label matcher, the source
 * flow service, the source form validation and the FOSS/Software page-kind
 * resolver). Before this class each carried its own near-identical copy.
 *
 * @license GPL-2.0-or-later
 */
final class EntityClassFilter {

	/**
	 * Parses a pipe-separated list of item ids (e.g. `Q302|Q5`) into a
	 * clean, deduped list. Malformed segments are dropped — the filter is
	 * an enhancement, never a hard error.
	 *
	 * @return string[]
	 */
	public static function parseItemIds( string $raw ): array {
		$ids = [];
		foreach ( explode( '|', $raw ) as $part ) {
			$part = trim( $part );
			if ( preg_match( '/^Q[1-9]\d*$/i', $part ) === 1 ) {
				$ids[] = strtoupper( $part );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Whether the item carries an `instance of` statement pointing at ANY of
	 * the given class ids. An empty class list is false (callers treat an
	 * empty scope as "no filter" before calling).
	 *
	 * @param string[] $classItemIds
	 */
	public static function hasAnyClass( Item $item, array $classItemIds, string $instanceOfPropertyId ): bool {
		if ( $classItemIds === [] ) {
			return false;
		}
		// NumericPropertyId exists in the MediaWiki runtime's newer
		// wikibase/data-model but not in the repo's unit-test vendor
		// (9.6.1) — fall back to the base PropertyId there.
		$propertyId = class_exists( \Wikibase\DataModel\Entity\NumericPropertyId::class )
			? new \Wikibase\DataModel\Entity\NumericPropertyId( $instanceOfPropertyId )
			: new PropertyId( $instanceOfPropertyId );
		foreach ( $item->getStatements()->getByPropertyId( $propertyId ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof EntityIdValue
				&& in_array( $value->getEntityId()->getSerialization(), $classItemIds, true )
			) {
				return true;
			}
		}
		return false;
	}

	/** Whether the item is `instance of` the given class id. */
	public static function hasClass( Item $item, string $classId, string $instanceOfPropertyId ): bool {
		return self::hasAnyClass( $item, [ $classId ], $instanceOfPropertyId );
	}
}
