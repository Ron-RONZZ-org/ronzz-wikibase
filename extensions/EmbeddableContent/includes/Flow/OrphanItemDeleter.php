<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Deletes the automatically-created item of a deleted Main-namespace page
 * when the item has nothing but that page: no statements, descriptions or
 * aliases, and no sitelink other than the deleted page.
 *
 * The auto-created item (PageItemCreator) carries only a label + sitelink, so
 * deleting the page leaves a useless orphan. An item with any real content is
 * left alone — the page deletion must never destroy authored statements.
 *
 * Never fatal: the page deletion has already succeeded; a failed item
 * deletion is logged at WARNING (the item can be deleted by hand).
 *
 * @license GPL-2.0-or-later
 */
final class OrphanItemDeleter {

	/** Site id of the local sitelink group (Hooks/ItemImage use the same). */
	private const SITE_ID = 'wikibase';

	/**
	 * PageDeleteComplete handler.
	 *
	 * @param ProperPageIdentity $page the deleted page (state before deletion)
	 * @param Authority $deleter who deleted the page
	 * @param string $reason the page-deletion reason
	 */
	public static function handle( ProperPageIdentity $page, Authority $deleter, string $reason ): void {
		if ( $page->getNamespace() !== NS_MAIN ) {
			return;
		}
		try {
			$title = Title::newFromPageIdentity( $page );
			$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( self::SITE_ID, $title->getPrefixedText() );
			if ( $itemId === null ) {
				return;
			}
			$item = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
			if ( !$item instanceof Item
				|| !self::shouldDelete( $item, $title->getPrefixedText() )
			) {
				return;
			}
			$user = User::newFromIdentity( $deleter->getUser() );
			WikibaseRepo::getEntityStore()->deleteEntity(
				new ItemId( $itemId->getSerialization() ),
				wfMessage( 'embeddablecontent-orphan-item-delete-reason', $title->getPrefixedText() )
					->inContentLanguage()->text(),
				$user
			);
		} catch ( \Throwable $e ) {
			LoggerFactory::getInstance( 'EmbeddableContent' )->warning(
				'OrphanItemDeleter: could not delete the item of {page}: {error}',
				[
					'page' => $page->getNamespace() . ':' . $page->getDBkey(),
					'error' => $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Whether the item is an empty auto-created shell for exactly this page.
	 * Pure (data-model only) — unit-tested without a MediaWiki runtime.
	 */
	public static function shouldDelete( Item $item, string $pageName ): bool {
		if ( !$item->getStatements()->isEmpty()
			|| !$item->getDescriptions()->isEmpty()
			|| !$item->getAliasGroups()->isEmpty()
		) {
			return false;
		}
		$links = $item->getSiteLinkList();
		if ( count( $links ) !== 1 || !$links->hasLinkWithSiteId( self::SITE_ID ) ) {
			return false;
		}
		return self::normalize( $links->getBySiteId( self::SITE_ID )->getPageName() )
			=== self::normalize( $pageName );
	}

	/** Page names are stored with spaces; underscore variants are equivalent. */
	private static function normalize( string $pageName ): string {
		return trim( str_replace( '_', ' ', $pageName ) );
	}

}
