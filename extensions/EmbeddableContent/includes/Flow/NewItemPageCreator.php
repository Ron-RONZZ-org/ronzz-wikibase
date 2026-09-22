<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use EmbeddableContent\Spec\LabelSanitizer;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Auto-creates a sitelinked classic page in the MAIN namespace for an item
 * created through Wikibase's Special:NewItem.
 *
 * The Add* flows each create their own per-kind page (Person:/Source:/
 * FOSS:/…); an item created with the plain "Create a new item" form has no
 * page at all. This handler closes that gap: when a NEW item page is saved
 * from Special:NewItem, a Main-namespace page titled with the item's label
 * (normalized to a usable title) is created and sitelinked to the item.
 *
 * Scope is deliberately narrow — ONLY the Special:NewItem form:
 *  - the Add* flows create their own pages (and fire the same hook);
 *  - API/script-created items (wbeditentity) are out of scope;
 *  - an item created with an explicit sitelink (the anonymous Sitelink-tab
 *    fallback passes site=wikibase&page=…) already has its page.
 *
 * The sitelink is written BEFORE the page (the ClassicPageCreator
 * convention) so the page's save-time parse can map it to the item. As with
 * the API flow, the `wikibase_item` page property may lag a few minutes
 * (eventual consistency via the job queue); the Sitelink tab reads the
 * sitelink table directly, so it is correct immediately.
 *
 * @license GPL-2.0-or-later
 */
final class NewItemPageCreator {

	/**
	 * Re-entrancy guard: creating the page saves the item (sitelink) and
	 * the Main page — both fire PageSaveComplete again. The namespace /
	 * EDIT_NEW gates already exclude them; this is cheap insurance.
	 */
	private static bool $inProgress = false;

	/**
	 * PageSaveComplete handler. $requestTitle is the title the current
	 * request is serving (RequestContext), used to restrict the behaviour
	 * to Special:NewItem.
	 */
	public static function handle(
		\MediaWiki\Page\WikiPage $wikiPage,
		UserIdentity $user,
		int $flags,
		?Title $requestTitle
	): void {
		if ( $requestTitle === null || !$requestTitle->isSpecial( 'NewItem' ) ) {
			return;
		}
		if ( ( $flags & EDIT_NEW ) === 0 ) {
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
		if ( !$itemId instanceof ItemId || self::$inProgress ) {
			return;
		}

		self::$inProgress = true;
		try {
			self::createMainPage( $itemId, $user );
		} finally {
			self::$inProgress = false;
		}
	}

	private static function createMainPage( ItemId $itemId, UserIdentity $user ): void {
		try {
			$item = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( !$item instanceof Item || $item->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' ) ) {
			return;
		}

		[ $label, $description ] = self::labelAndDescription( $item );
		$label = LabelSanitizer::normalizeForTitle( $label );
		if ( $label === '' ) {
			return;
		}
		try {
			$title = Title::makeTitle( NS_MAIN, $label );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( $title === null || $title->getNamespace() !== NS_MAIN || !$title->isValid() ) {
			return;
		}
		// The page may already exist (hand-created, or from a previous run):
		// sitelink the item to it rather than skipping (the hook has no UI to
		// confirm, so it links best-effort; the Sitelink tab lets the user
		// correct it). NEVER steal a page already sitelinked to ANOTHER item
		// — the sitelink is unique per page and setting it would throw a
		// StorageException. ClassicPageCreator sitelinks first and skips
		// creation when the page already exists.
		$linkOwner = WikibaseRepo::getStore()->newSiteLinkStore()
			->getItemIdForLink( 'wikibase', $title->getPrefixedText() );
		if ( $linkOwner !== null && $linkOwner->getSerialization() !== $itemId->getSerialization() ) {
			return;
		}

		( new ClassicPageCreator() )->createFor(
			new ClassicPageSpec( NS_MAIN, '' ),
			$label,
			[
				'itemId' => $itemId->getSerialization(),
				'description' => $description,
			],
			$user
		);
	}

	/**
	 * The label + description used for the page title/lead, preferring the
	 * instance's term languages (en/fr/eo), then any available term.
	 *
	 * @return array{0:string,1:string} [ label, description ]
	 */
	private static function labelAndDescription( Item $item ): array {
		$labels = $item->getLabels()->toTextArray();
		$descriptions = $item->getDescriptions()->toTextArray();
		foreach ( [ 'en', 'fr', 'eo' ] as $language ) {
			if ( isset( $labels[$language] ) ) {
				return [ $labels[$language], $descriptions[$language] ?? '' ];
			}
		}
		if ( $labels === [] ) {
			return [ '', '' ];
		}
		$language = (string)array_key_first( $labels );
		return [ $labels[$language], $descriptions[$language] ?? '' ];
	}
}
