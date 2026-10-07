<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

use EmbeddableContent\Spec\EntityLabelText;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;

/**
 * Resolves a content item's author / source provenance into display strings,
 * shared by the `{{#content:}}` parser function (`ContentPayload`) and the
 * embed preview (`ContentRenderer`) so the two can never drift.
 *
 * Authors render as the item's plain label (English preferred); sources
 * render as an italic link to their `wikibase` sitelink page
 * (`''[[Source:Beloved (Book)|Beloved]]''`), or a plain italic label when
 * the item has no page. The final `-author, ''source''` join lives in
 * `ContentWikitext::quotationAttribution` (pure) — this class only resolves
 * the entities.
 *
 * @license GPL-2.0-or-later
 */
final class ProvenanceWikitext {

	/** Site id of the local sitelink group (also hardcoded in Hooks/ContentPayload). */
	private const SITE_ID = 'wikibase';

	/**
	 * The item-id values of a wikibase-item property, in statement order.
	 *
	 * @return string[]
	 */
	public static function entityIds( Item $item, ?string $propertyId ): array {
		if ( $propertyId === null || $propertyId === '' ) {
			return [];
		}
		$ids = [];
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof \Wikibase\DataModel\Snak\PropertyValueSnak
				|| $snak->getPropertyId()->getSerialization() !== $propertyId
			) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$ids[] = $value->getEntityId()->getSerialization();
			}
		}
		return $ids;
	}

	/**
	 * Author display labels (plain, English preferred) for a property.
	 *
	 * @return string[]
	 */
	public static function authorLabels( Item $item, ?string $propertyId, EntityLookup $lookup ): array {
		$labels = [];
		foreach ( self::entityIds( $item, $propertyId ) as $id ) {
			$labels[] = self::entityInfo( $id, $lookup )['label'] ?? $id;
		}
		return $labels;
	}

	/**
	 * Source display wikitext for a property.
	 *
	 * @return string[]
	 */
	public static function sourceWikitexts( Item $item, ?string $propertyId, EntityLookup $lookup ): array {
		$sources = [];
		foreach ( self::entityIds( $item, $propertyId ) as $id ) {
			$sources[] = self::sourceWikitext( $id, $lookup );
		}
		return $sources;
	}

	/**
	 * The italic wikitext for a source: a link to its classic page when it
	 * has one (`''[[Source:Beloved (Book)|Beloved]]''`), else the plain
	 * italic label (`''Beloved''`). Never throws — a malformed/missing
	 * entity degrades to the bare id.
	 */
	private static function sourceWikitext( string $sourceId, EntityLookup $lookup ): string {
		$info = self::entityInfo( $sourceId, $lookup );
		$label = $info['label'] ?? $sourceId;
		if ( $info['page'] === null || $info['page'] === '' ) {
			return "''" . $label . "''";
		}
		return "''[[" . $info['page'] . '|' . $label . "]]''";
	}

	/**
	 * The en label + local classic-page title of an item, or nulls when it
	 * does not exist.
	 *
	 * @return array{label:?string,page:?string}
	 */
	private static function entityInfo( string $itemId, EntityLookup $lookup ): array {
		try {
			$item = $lookup->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return [ 'label' => null, 'page' => null ];
		}
		if ( !$item instanceof Item ) {
			return [ 'label' => null, 'page' => null ];
		}
		$label = EntityLabelText::of( $item );
		$siteLinks = $item->getSiteLinkList();
		// getBySiteId() THROWS when the site link is absent — guard it.
		$sitelink = $siteLinks->hasLinkWithSiteId( self::SITE_ID )
			? $siteLinks->getBySiteId( self::SITE_ID )
			: null;
		return [
			'label' => $label,
			'page' => $sitelink?->getPageName(),
		];
	}

}
