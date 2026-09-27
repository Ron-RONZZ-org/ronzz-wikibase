<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

use MediaWiki\Title\TitleFactory;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Lib\Store\SiteLinkLookup;

/**
 * Resolves an item to the URL of its classic wiki page, via the Wikibase
 * sitelink store (2026-09 UX batch).
 *
 * The Add* flows sitelink their classic per-kind pages (Person:/Source:/
 * Collective:/FOSS:/Software:) under the site id `wikibase`; an item without
 * such a page (API-created items, book excerpts, …) resolves to null and the
 * citation renders without a link.
 *
 * @license GPL-2.0-or-later
 */
final class SourcePageResolver {

	/** The sitelink site id the classic-page flows use. */
	public const SITE_ID = 'wikibase';

	private SiteLinkLookup $siteLinks;

	private TitleFactory $titleFactory;

	public function __construct( SiteLinkLookup $siteLinks, TitleFactory $titleFactory ) {
		$this->siteLinks = $siteLinks;
		$this->titleFactory = $titleFactory;
	}

	/**
	 * @return string|null the local URL of the item's classic page, or null
	 *  when the item has none
	 */
	public function pageUrl( ItemId $itemId ): ?string {
		foreach ( $this->siteLinks->getSiteLinksForItem( $itemId ) as $link ) {
			if ( $link->getSiteId() !== self::SITE_ID ) {
				continue;
			}
			$title = $this->titleFactory->newFromText( $link->getPageName() );
			return $title !== null ? $title->getLocalURL() : null;
		}
		return null;
	}
}
