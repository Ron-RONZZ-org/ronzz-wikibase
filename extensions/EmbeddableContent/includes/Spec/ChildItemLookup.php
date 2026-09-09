<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\Repo\WikibaseRepo;

/**
 * MediaWiki-bound facade for the child-item listing (the Source-page
 * analogue of the quotation listing): runs the ChildItemFinder against the
 * instance's WDQS endpoint for every CHILD class of the parent item's own
 * source class — the child keys come from the config's `sourceParents`
 * map (child key → parent key) inverted. Shared by the Special:ChildItemsOf
 * page and the `{{#child-items-of:}}` parser function so both surfaces
 * behave identically.
 *
 * Exception-safe by contract: an unreachable WDQS (or a config without the
 * source vocabulary / SPARQL endpoint) yields null — the caller degrades
 * to "no data", never a 500. The pure logic (query building, row parsing)
 * lives in ChildItemFinder and is unit-tested without a MediaWiki runtime.
 *
 * @license GPL-2.0-or-later
 */
final class ChildItemLookup {

	/**
	 * Child items of the given parent item, grouped by child class KEY:
	 *   [ 'bookExcerpt' => [ {qid,label}, … ], … ]
	 * Empty when the parent has no child classes (its class is not a
	 * configured parent) or no children; null when the lookup cannot run
	 * (no SPARQL endpoint, WDQS unreachable, config shape).
	 *
	 * @return array<string,array<int,array{qid:string,label:string}>>|null
	 */
	public static function findForParent( EmbeddableContentConfig $config, string $parentItemId ): ?array {
		try {
			$parent = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $parentItemId ) );
			if ( !$parent instanceof Item ) {
				return null;
			}
			$parentKey = self::sourceClassKeyOf( $config, $parent );
			if ( $parentKey === null ) {
				return [];
			}
			// The child class keys of this parent: invert sourceParents()
			// (child key → parent key) and keep those whose parent matches.
			$childKeys = [];
			foreach ( $config->sourceParents() as $childKey => $parentOfChild ) {
				if ( $parentOfChild === $parentKey ) {
					$childKeys[] = $childKey;
				}
			}
			if ( $childKeys === [] ) {
				return [];
			}

			$endpoint = $config->sparqlUrl();
			$partOf = $config->sourceParentPropertyId();
			if ( $endpoint === null || $partOf === null ) {
				error_log( 'ChildItemLookup: no sparqlUrl/partOf for parent ' . $parentItemId );
				return null;
			}
			$prefixes = self::entityPrefixes();
			if ( $prefixes === null ) {
				error_log( 'ChildItemLookup: wgServer not set — cannot derive entity prefixes' );
				return null;
			}
			[ $wd, $wdt ] = $prefixes;

			$out = [];
			foreach ( $childKeys as $childKey ) {
				$childClassId = $config->sourceClasses()[$childKey] ?? null;
				if ( $childClassId === null ) {
					continue;
				}
				$finder = new ChildItemFinder(
					static fn ( string $query ): ?array => self::runSparql( $endpoint, $query )
				);
				$rows = $finder->findForParent(
					$parentItemId,
					$childClassId,
					$partOf,
					$config->instanceOfPropertyId(),
					$wd,
					$wdt
				);
				if ( $rows === null ) {
					return null;
				}
				if ( $rows !== [] ) {
					$out[$childKey] = $rows;
				}
			}
			return $out;
		} catch ( \Throwable $e ) {
			error_log( 'ChildItemLookup: findForParent(' . $parentItemId . ') failed: '
				. get_class( $e ) . ': ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * The source-class key of an item ('' when the item's instance-of does
	 * not include any configured source class).
	 */
	public static function sourceClassKeyOf( EmbeddableContentConfig $config, Item $item ): ?string {
		$instanceOf = new NumericPropertyId( $config->instanceOfPropertyId() );
		$classes = [];
		foreach ( $item->getStatements()->getByPropertyId( $instanceOf ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak ) {
				$value = $snak->getDataValue();
				if ( $value instanceof EntityIdValue ) {
					$classes[] = $value->getEntityId()->getSerialization();
				}
			}
		}
		foreach ( $config->sourceClasses() as $key => $classId ) {
			if ( in_array( $classId, $classes, true ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Refreshes the parser cache of the given parent items' classic pages
	 * (the "child items" auto-link row on the Source: pages). Creating or
	 * re-parenting a child item does NOT touch the parent item's revision,
	 * so the parser-cache dependency never fires — the page must be
	 * invalidated explicitly. Best-effort: a failure only delays the row
	 * refresh until the parser-cache TTL — it never breaks the item save.
	 *
	 * @param string[] $parentItemIds
	 */
	public static function invalidateParentPages( array $parentItemIds ): void {
		foreach ( array_unique( array_filter( $parentItemIds, 'is_string' ) ) as $itemId ) {
			if ( preg_match( '/^Q[1-9]\d*$/i', $itemId ) !== 1 ) {
				continue;
			}
			try {
				$link = WikibaseRepo::getStore()->newSiteLinkStore()
					->getLinkForItemId( new ItemId( $itemId ) );
				$title = Title::newFromText( (string)( $link['pageName'] ?? '' ) );
				if ( $title !== null && $title->exists() ) {
					$title->invalidateCache();
				}
			} catch ( \Throwable $e ) {
				// Best-effort (see above).
			}
		}
	}

	/** @return array{string,string}|null [wd, wdt] entity URI bases, or null */
	private static function entityPrefixes(): ?array {
		$server = $GLOBALS['wgServer'] ?? '';
		if ( !is_string( $server ) || $server === '' ) {
			return null;
		}
		$wd = rtrim( $server, '/' ) . '/entity/';
		return [ $wd, str_replace( '/entity/', '/prop/direct/', $wd ) ];
	}

	/** @return array<int,array<string,mixed>>|null */
	private static function runSparql( string $endpoint, string $query ): ?array {
		try {
			return SparqlRunner::select( $endpoint, $query );
		} catch ( \Throwable $e ) {
			error_log( 'ChildItemLookup: SPARQL request to ' . $endpoint . ' threw '
				. get_class( $e ) . ': ' . $e->getMessage() );
			return null;
		}
	}
}
