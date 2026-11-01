<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * The classic wiki page sitelinked to an item — the item→page direction of
 * the `wikibase` site link.
 *
 * `SiteLinkLookup::getLinkForItemId()` does NOT exist in this Wikibase
 * version (only `getItemIdForLink()` for the page→item direction,
 * `getLinks()` and `getSiteLinksForItem()`); four call sites used the
 * nonexistent method inside `catch ( Throwable )` blocks, so each one
 * silently degraded: the child-items listing linked every child to `Item:`,
 * and the quotation/child-listing cache invalidators never ran. This class
 * is the ONE item→page resolver (the Item page's "Copy internal mention"
 * button, the child-items listing and both cache invalidators).
 *
 * Best-effort contract: a store failure yields null (the callers decide
 * whether that means "no button" or "skip the refresh").
 *
 * @license GPL-2.0-or-later
 */
final class ItemPageTitle {

	/**
	 * The page title of the item's `wikibase` sitelink, or null when the
	 * item has no such sitelink, the page name is not a valid title, or the
	 * store lookup fails. Existence is NOT checked — callers that link or
	 * invalidate a page check it themselves (the same page may be a
	 * redlink).
	 */
	public static function forItem( string $itemId ): ?Title {
		try {
			$links = WikibaseRepo::getStore()->newSiteLinkStore()
				->getSiteLinksForItem( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		foreach ( $links as $link ) {
			if ( $link->getSiteId() !== 'wikibase' ) {
				continue;
			}
			return Title::newFromText( $link->getPageName() );
		}
		return null;
	}
}
