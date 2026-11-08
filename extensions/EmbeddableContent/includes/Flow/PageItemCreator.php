<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\EntityClassFilter;
use EmbeddableContent\ItemIdentityPolicy;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Lib\TermIndexEntry;
use Wikibase\Repo\WikibaseRepo;
use Wikimedia\Rdbms\DBError;

/**
 * Auto-creates (or reuses) the sitelinked Wikibase item for a NEW
 * Main-namespace classic page — the reverse of NewItemPageCreator, and the
 * backfill for pages that predate the item.
 *
 * NewItemPageCreator creates the page for an item created through
 * Special:NewItem; a page written directly by an editor (the classic
 * wikitext workflow) had no item at all. This handler closes that gap: when
 * a NEW Main-namespace page is saved and is NOT already sitelinked to an
 * item, the page title becomes the label of a new item (in the content
 * language), which is sitelinked to the page.
 *
 * Scope is deliberately narrow — ONLY new Main-namespace page saves:
 *  - an existing item with the exact same label is REUSED and sitelinked
 *    (never duplicated; the link-never-clobber rule NewItemPageCreator
 *    applies in the other direction);
 *  - a page already sitelinked is skipped — in particular the page the
 *    NewItem hook just created, which writes its sitelink BEFORE the page;
 *  - the Add* flows' pages live in the custom namespaces (Person:/Source:/…)
 *    and are skipped; the Item: save this handler triggers is skipped by the
 *    same namespace gate plus the re-entrancy guard;
 *  - redirects and non-new saves (EDIT_UPDATE) are skipped.
 *
 * Never fatal: the page save must succeed even when the item cannot be
 * created, so failures are logged at WARNING (never swallowed silently).
 *
 * @license GPL-2.0-or-later
 */
final class PageItemCreator {

	/** Re-entrancy guard: creating the item saves an Item: page (same hook). */
	private static bool $inProgress = false;

	/**
	 * PageSaveComplete handler. Called from Hooks::onPageSaveComplete after
	 * NewItemPageCreator (the two are mutually exclusive by construction:
	 * NewItemPageCreator's page is already sitelinked when it is saved).
	 */
	public static function handle(
		WikiPage $wikiPage,
		UserIdentity $user,
		int $flags
	): void {
		if ( ( $flags & EDIT_NEW ) === 0 ) {
			return;
		}
		$title = $wikiPage->getTitle();
		if ( $title === null || $title->getNamespace() !== NS_MAIN || $title->isRedirect() ) {
			return;
		}
		if ( self::$inProgress || self::linkedItemId( $title ) !== null ) {
			return;
		}

		self::$inProgress = true;
		try {
			self::createOrReuse( $title, $user );
		} finally {
			self::$inProgress = false;
		}
	}

	private static function createOrReuse( Title $title, UserIdentity $user ): void {
		$label = trim( $title->getText() );
		if ( $label === '' ) {
			return;
		}

		$existing = self::findItemIdByLabel( $label );
		if ( $existing !== null ) {
			if ( self::canAdoptLabelMatch( $existing, $title ) ) {
				self::sitelink( $existing, $title, $user );
				return;
			}
			self::logImageLabelCollision( $existing, $title );
		}

		$item = new Item();
		$item->setLabel( self::contentLanguage(), $label );
		$item->getSiteLinkList()->setNewSiteLink( 'wikibase', $title->getPrefixedText() );
		try {
			WikibaseRepo::getEntityStore()->saveEntity(
				$item,
				self::msg( 'embeddablecontent-page-item-create-summary', $label ),
				$user,
				EDIT_NEW
			);
			// Also write the sitelink table synchronously (the entity save's
			// secondary data update may run deferred — the Add* pattern).
			WikibaseRepo::getStore()->newSiteLinkStore()->saveLinksOfItem( $item );
		} catch ( \Throwable $e ) {
			self::logWarning( 'could not create the item', $title, $e );
		}
	}

	/**
	 * Sitelinks an existing item to the page (entity revision + sitelink
	 * table). Never steals: an item that already has a wikibase sitelink is
	 * left alone.
	 */
	private static function sitelink( string $itemId, Title $title, UserIdentity $user ): void {
		try {
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			self::logWarning( "could not load item $itemId", $title, $e );
			return;
		}
		if ( !$item instanceof Item || $item->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' ) ) {
			return;
		}
		$item->getSiteLinkList()->setNewSiteLink( 'wikibase', $title->getPrefixedText() );
		try {
			WikibaseRepo::getEntityStore()->saveEntity(
				$item,
				self::msg( 'embeddablecontent-page-item-link-summary', $title->getPrefixedText() ),
				$user,
				EDIT_UPDATE
			);
			WikibaseRepo::getStore()->newSiteLinkStore()->saveLinksOfItem( $item );
		} catch ( \Throwable $e ) {
			self::logWarning( "could not sitelink $itemId", $title, $e );
		}
	}

	/** The item id sitelinked to the page, or null when the page is unlinked. */
	private static function linkedItemId( Title $title ): ?string {
		try {
			$id = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( 'wikibase', $title->getPrefixedText() );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $id?->getSerialization();
	}

	/**
	 * Whether a LABEL match may be reused for this Main-namespace page. An
	 * image item (or an item sitelinked to a File: page) belongs to a file,
	 * never to a same-titled article — e.g. the article "Logic" must not
	 * adopt the image item of File:Logic.png. Any other match keeps the
	 * legacy reuse (a failure to inspect the candidate falls back to reuse,
	 * which can never duplicate an item).
	 */
	private static function canAdoptLabelMatch( string $itemId, Title $title ): bool {
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			self::logWarning( "could not load label candidate $itemId", $title, $e );
			return true;
		}
		if ( !$entity instanceof Item ) {
			return true;
		}
		$links = $entity->getSiteLinkList();
		$sitelinkTitle = $links->hasLinkWithSiteId( 'wikibase' )
			? Title::newFromText( $links->getBySiteId( 'wikibase' )->getPageName() )
			: null;
		$sitelinkIsFilePage = $sitelinkTitle !== null && $sitelinkTitle->getNamespace() === NS_FILE;
		return ItemIdentityPolicy::canAdoptPageLabelMatch(
			self::isImageItem( $entity ),
			$sitelinkIsFilePage
		);
	}

	/** Whether the item is `instance of` the configured image class. */
	private static function isImageItem( Item $item ): bool {
		try {
			$config = MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' );
			if ( !$config instanceof EmbeddableContentConfig ) {
				return false;
			}
			$classId = $config->imageClasses()['image'] ?? null;
			if ( $classId === null ) {
				return false;
			}
			return EntityClassFilter::hasClass( $item, $classId, $config->instanceOfPropertyId() );
		} catch ( \Throwable $e ) {
			// Non-fatal: an unconfigured instance keeps the legacy reuse.
			LoggerFactory::getInstance( 'EmbeddableContent' )->warning(
				'PageItemCreator: image-class probe failed for {item}: {error}',
				[
					'item' => $item->getId()?->getSerialization() ?? 'unknown',
					'error' => $e->getMessage(),
				]
			);
			return false;
		}
	}

	/** Log the rejected file-image label match (observable, never fatal). */
	private static function logImageLabelCollision( string $itemId, Title $title ): void {
		LoggerFactory::getInstance( 'EmbeddableContent' )->warning(
			'PageItemCreator: label match {item} belongs to a file image item, not {page}; creating a new item',
			[ 'item' => $itemId, 'page' => $title->getPrefixedText() ]
		);
	}

	/** Item id whose content-language label matches exactly (case-insensitive), or null. */
	private static function findItemIdByLabel( string $label ): ?string {
		try {
			$entries = WikibaseRepo::getMatchingTermsLookupFactory()
				->getLookupForSource( WikibaseRepo::getLocalEntitySource() )
				->getMatchingTerms(
					$label,
					Item::ENTITY_TYPE,
					self::contentLanguage(),
					TermIndexEntry::TYPE_LABEL,
					[ 'caseSensitive' => false ]
				);
		} catch ( DBError $e ) {
			return null;
		}
		foreach ( $entries as $entry ) {
			$id = $entry->getEntityId();
			if ( $id instanceof ItemId ) {
				return $id->getSerialization();
			}
		}
		return null;
	}

	private static function contentLanguage(): string {
		return MediaWikiServices::getInstance()->getContentLanguage()->getCode();
	}

	private static function msg( string $key, string $param ): string {
		return wfMessage( $key, $param )->inContentLanguage()->text();
	}

	private static function logWarning( string $what, Title $title, \Throwable $e ): void {
		LoggerFactory::getInstance( 'EmbeddableContent' )->warning(
			'PageItemCreator: {what} for {page}: {error}',
			[ 'what' => $what, 'page' => $title->getPrefixedText(), 'error' => $e->getMessage() ]
		);
	}

}
