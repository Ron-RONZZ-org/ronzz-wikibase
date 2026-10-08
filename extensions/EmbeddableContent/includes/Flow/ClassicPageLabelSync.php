<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Keeps a classic page's TITLE in sync with its item's label on a DIRECT
 * Item: page edit (the Wikibase UI label field), an API edit
 * (`wbeditentity`) or the MCP `wikibase-edit-entity`.
 *
 * The Special:Update* forms already rename the page
 * (`UpdateExternalEntityFlow::renameClassicPage`); a direct label edit had
 * no such path, so the `Source:`/`Person:`/… page stayed at the old title.
 * This `PageSaveComplete` handler closes that gap: when an Item: page is
 * saved (an update, not a create), the saved item has a `wikibase` sitelink,
 * and the title derived from its **English label** (fallback: the item's
 * term-language label) differs from the sitelinked page's current title, the
 * page is moved to the new title — a redirect is left behind, and the
 * sitelink is re-pointed.
 *
 * Scope and safety:
 *  - only Item: entity saves (the Item namespace) — classic-page moves and
 *    ordinary page saves are skipped;
 *  - `EDIT_NEW` is skipped (the Add* flows write their sitelink + page in a
 *    controlled order; this handler would only race them);
 *  - only the CLASSIC per-kind pages (Source:/Person:/Collective:/FOSS:/
 *    Software:) are renamed — a Main-namespace page (the `Special:NewItem` /
 *    `PageItemCreator` auto-pages) may be titled differently from the item
 *    label on purpose, so it is never moved here;
 *  - the shared `ClassicPageRenamer` suppression guard skips the save the
 *    Update* flow owns;
 *  - a no-op whenever the derived title equals the current sitelink title,
 *    so the Add* sitelink writes and this handler's own sitelink save never
 *    loop.
 *
 * Never fatal: a missing item/sitelink or a refused move simply leaves the
 * page as-is (the label edit itself has already succeeded).
 *
 * @license GPL-2.0-or-later
 */
final class ClassicPageLabelSync {

	/**
	 * PageSaveComplete handler (item saves only).
	 *
	 * @param WikiPage $wikiPage the page whose save completed (the Item: page)
	 * @param UserIdentity $user
	 * @param int $flags
	 */
	public static function handle( WikiPage $wikiPage, UserIdentity $user, int $flags ): void {
		if ( ClassicPageRenamer::isSuppressed() ) {
			return;
		}
		if ( ( $flags & EDIT_NEW ) !== 0 ) {
			return;
		}
		$title = $wikiPage->getTitle();
		if ( $title === null ) {
			return;
		}
		$itemNamespace = WikibaseRepo::getEntityNamespaceLookup()->getEntityNamespace( Item::ENTITY_TYPE );
		if ( $itemNamespace === false || $title->getNamespace() !== $itemNamespace ) {
			return;
		}
		try {
			$itemId = WikibaseRepo::getEntityIdParser()->parse( $title->getText() );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( !$itemId instanceof ItemId ) {
			return;
		}

		try {
			$item = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( !$item instanceof Item ) {
			return;
		}
		$sitelinks = $item->getSiteLinkList();
		if ( !$sitelinks->hasLinkWithSiteId( 'wikibase' ) ) {
			return;
		}
		try {
			$oldTitle = Title::newFromText( $sitelinks->getBySiteId( 'wikibase' )->getPageName() );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( $oldTitle === null || !self::isClassicEntityNamespace( $oldTitle->getNamespace() ) ) {
			// Only the per-kind classic pages are renamed: a Main-namespace
			// page may be titled differently from the item label on purpose
			// (the PageItemCreator label match), so it is never moved here.
			return;
		}

		$label = self::titleLabel( $item );
		if ( $label === null || trim( $label ) === '' ) {
			return;
		}

		ClassicPageRenamer::renameForLabel(
			$item,
			$label,
			$oldTitle->getNamespace(),
			$user,
			wfMessage( 'embeddablecontent-update-move-summary', $label )->inContentLanguage()->text(),
			wfMessage( 'embeddablecontent-update-sitelink-summary', $label )->inContentLanguage()->text()
		);
	}

	/**
	 * The label the classic page title should follow: the English label when
	 * the item carries one, else its term-language label (the same label the
	 * page was created from — e.g. an AddSource item stored under a
	 * non-English `labelLanguage`). Null when the item has no label.
	 */
	private static function titleLabel( Item $item ): ?string {
		if ( $item->getLabels()->hasTermForLanguage( 'en' ) ) {
			return $item->getLabels()->getByLanguage( 'en' )->getText();
		}
		$labels = $item->getLabels()->toTextArray();
		if ( $labels === [] ) {
			return null;
		}
		return (string)reset( $labels );
	}

	/**
	 * The classic per-kind namespaces (Source: / Person: / Collective: /
	 * FOSS: / Software:), defined by the instance LocalSettings. The
	 * defined() guards keep the handler safe on a wiki without them.
	 */
	private static function isClassicEntityNamespace( int $namespace ): bool {
		foreach ( [ 'NS_SOURCE', 'NS_PERSON', 'NS_COLLECTIVE', 'NS_FOSS', 'NS_SOFTWARE' ] as $constant ) {
			if ( defined( $constant ) && $namespace === constant( $constant ) ) {
				return true;
			}
		}
		return false;
	}
}
