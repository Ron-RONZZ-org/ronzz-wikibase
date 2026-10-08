<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use EmbeddableContent\Spec\PageTitle;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use Wikibase\DataModel\Entity\Item;
use Wikibase\Repo\WikibaseRepo;

/**
 * The single classic-page rename primitive: move an item's sitelinked
 * classic page to the title derived from a (new) label, leaving a redirect
 * behind, then point the `wikibase` sitelink at the new page name.
 *
 * Two callers share it, so they can never drift:
 *  - `Spec/UpdateExternalEntityFlow::renameClassicPage` (a label/page-kind
 *    change through a Special:Update* form), and
 *  - `Flow/ClassicPageLabelSync` (a direct en-label edit on the Item: page,
 *    an API edit or the MCP `wikibase-edit-entity`).
 *
 * The old title is read from the item's OWN sitelink (ground truth — it
 * knows where the page is); the new title from the label + the target
 * namespace (the `Spec/PageTitle` contract: first-letter capitalization +
 * title-forbidden-character normalization). A failure leaves the old page
 * in place — the item update itself is never rolled back.
 *
 * The rename may move over the SINGLE-REVISION redirect a previous rename
 * left behind, so an accidental edit can be reverted (label backtracking);
 * a real page is never clobbered. The `suppress` guard keeps the
 * `PageSaveComplete` label-sync handler from re-entering while a caller
 * (the Update flow) already owns the rename, and while this method's own
 * sitelink save fires the same hook.
 *
 * @license GPL-2.0-or-later
 */
final class ClassicPageRenamer {

	/** Nesting depth of the suppression guard (static: the hook is global). */
	private static int $suppressDepth = 0;

	/**
	 * Suppress (or release) the PageSaveComplete label-sync while a caller
	 * performs its own save/rename, so the rename is not attempted twice.
	 * Depth-counted for safety across nested entity saves.
	 */
	public static function suppress( bool $on ): void {
		self::$suppressDepth += $on ? 1 : -1;
		if ( self::$suppressDepth < 0 ) {
			self::$suppressDepth = 0;
		}
	}

	public static function isSuppressed(): bool {
		return self::$suppressDepth > 0;
	}

	/**
	 * Moves the item's sitelinked classic page to the label-derived title in
	 * $namespace (a redirect is left behind) and updates the sitelink.
	 *
	 * @param Item $item the item (carrying its current `wikibase` sitelink)
	 * @param string $newLabel the label to derive the new title from
	 * @param int $namespace the target namespace (the current sitelink
	 *  page's namespace on a label edit, or the record's page namespace)
	 * @param string $moveSummary edit summary of the page move
	 * @param string $sitelinkSummary edit summary of the sitelink update
	 * @param string[] $changeTags tags for the move (the Update* flow passes
	 *  its historical `['movetalk', 'movesubpages']`; the label-sync hook
	 *  passes none)
	 * @return bool true when the page was moved
	 */
	public static function renameForLabel(
		Item $item,
		string $newLabel,
		int $namespace,
		UserIdentity $user,
		string $moveSummary,
		string $sitelinkSummary,
		array $changeTags = []
	): bool {
		if ( self::isSuppressed() ) {
			return false;
		}
		if ( trim( $newLabel ) === '' ) {
			return false;
		}
		$sitelinks = $item->getSiteLinkList();
		if ( !$sitelinks->hasLinkWithSiteId( 'wikibase' ) ) {
			// No sitelink → no page to move.
			return false;
		}
		try {
			$oldTitle = Title::newFromText( $sitelinks->getBySiteId( 'wikibase' )->getPageName() );
		} catch ( \Throwable $e ) {
			return false;
		}
		$newTitle = PageTitle::fromLabel( $newLabel, $namespace );
		if ( $oldTitle === null || $newTitle === null
			|| $oldTitle->equals( $newTitle ) || !$oldTitle->exists()
		) {
			return false;
		}
		// Move over the single-revision redirect a previous rename left
		// behind (label backtracking); never clobber a real page.
		if ( $newTitle->exists()
			&& ( !$newTitle->isRedirect() || !$newTitle->isSingleRevRedirect() )
		) {
			return false;
		}

		self::suppress( true );
		try {
			$movePage = MediaWikiServices::getInstance()->getMovePageFactory()
				->newMovePage( $oldTitle, $newTitle );
			$status = $movePage->move( $user, $moveSummary, true, $changeTags );
			if ( !$status->isOK() ) {
				// The move may be refused (e.g. the target is not overwritable)
				// — leave the old page in place, the item update stands.
				return false;
			}

			// Point the sitelink at the new page name (entity revision + the
			// sitelink table, the synchronous write the Add* flows use).
			if ( $item->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' ) ) {
				$item->getSiteLinkList()->setNewSiteLink( 'wikibase', $newTitle->getPrefixedText() );
				WikibaseRepo::getEntityStore()->saveEntity(
					$item,
					$sitelinkSummary,
					$user,
					EDIT_UPDATE
				);
				WikibaseRepo::getStore()->newSiteLinkStore()->saveLinksOfItem( $item );
			}
		} catch ( \Throwable $e ) {
			// Best-effort: the item update is never rolled back.
			return false;
		} finally {
			self::suppress( false );
		}
		return true;
	}
}
