<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * The exact-label collision the class-filtered duplicate guard cannot see:
 * an existing local item with the record's label, whose own `wikibase`
 * sitelink (if any) points at a DIFFERENT page than the one the Add* flow
 * is about to create.
 *
 * The reported case: a Main-namespace `Club of Rome` page auto-created item
 * Q2655 (label only); the page was deleted; AddCollective then silently
 * reused Q2655, could not move its stale sitelink, and created an unlinked
 * `Collective:Club of Rome` page.
 *
 * Target-page ownership is deliberately NOT handled here: when the target
 * page is already sitelinked to another item, the existing duplicate guard /
 * link-confirm flow stays in charge ("keep current logic in case of
 * potential duplicate").
 *
 * @license GPL-2.0-or-later
 */
final class CollisionFinder {

	/** Site id of the local sitelink group (the Add* flows use the same). */
	private const SITE_ID = 'wikibase';

	/**
	 * @param string $label the record's primary label (without a duplicate suffix)
	 * @param string $language the label language used by the create-or-skip lookup
	 * @param Title|null $targetTitle the classic page the flow will create
	 * @return array{itemId:string,label:string,currentSitelink:?string,targetTitle:?string,targetExists:bool}|null
	 */
	public static function find( string $label, string $language, ?Title $targetTitle ): ?array {
		$label = trim( $label );
		if ( $label === '' ) {
			return null;
		}
		$itemId = ItemLabelLookup::findIdByLabel( $label, $language );
		if ( $itemId === null ) {
			return null;
		}
		try {
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( !$item instanceof Item ) {
			return null;
		}

		$targetName = $targetTitle?->getPrefixedText();
		$currentSitelink = null;
		if ( $item->getSiteLinkList()->hasLinkWithSiteId( self::SITE_ID ) ) {
			$currentSitelink = $item->getSiteLinkList()
				->getBySiteId( self::SITE_ID )->getPageName();
		}

		if ( $targetName !== null ) {
			// A page already owned by another item is a potential duplicate:
			// the existing duplicate/link-confirm flow keeps that case.
			$owner = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( self::SITE_ID, $targetName );
			if ( $owner !== null ) {
				return null;
			}
			// Idempotent: the item already points at this exact page.
			if ( $currentSitelink !== null
				&& self::normalize( $currentSitelink ) === self::normalize( $targetName )
			) {
				return null;
			}
		}

		return [
			'itemId' => $itemId,
			'label' => $label,
			'currentSitelink' => $currentSitelink,
			'targetTitle' => $targetName,
			'targetExists' => $targetTitle !== null && $targetTitle->exists(),
		];
	}

	private static function normalize( string $pageName ): string {
		return trim( str_replace( '_', ' ', $pageName ) );
	}

}
