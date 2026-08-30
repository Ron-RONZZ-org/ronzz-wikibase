<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use MediaWiki\Content\WikitextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Creates the classic wiki page for an item and sitelinks it — the
 * afterCreate machinery of the Add* flows, shared by the browser forms and
 * the API modules (action=addsource …). The sitelink is written BEFORE the
 * page: the page's save-time parse must find the link or its wikibase_item
 * page property stays stale and the infobox renders empty. Page names are
 * stored WITH SPACES (getItemIdForLink normalizes underscores away) — a
 * prefixed DB key would be a silent mismatch.
 *
 * MediaWiki-bound; exercised by the integration/E2E suites rather than the
 * pure-PHP unit suite.
 *
 * @license GPL-2.0-or-later
 */
final class ClassicPageCreator {

	/**
	 * Creates the page and sitelink for an item. Returns the page's prefixed
	 * title, or null when no page applies (the label is unusable as a page
	 * title, or the page could not be created — the item still exists).
	 *
	 * @param array<string,mixed> $record
	 */
	public function createFor( ClassicPageSpec $spec, string $label, array $record, UserIdentity $user ): ?string {
		$title = $this->pageTitleFor( $spec, $label );
		if ( $title === null ) {
			return null;
		}

		$itemId = (string)( $record['itemId'] ?? '' );
		if ( $itemId !== '' ) {
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
			if ( $item instanceof Item ) {
				$this->linkPageToItem( $item, $title, $label, $user );
			}
		}

		if ( !$title->exists() ) {
			return $this->createClassicPage( $spec, $title, $record, $label, $user ) ? $title->getPrefixedText() : null;
		}
		return $title->getPrefixedText();
	}

	/**
	 * The page title for a label, or null when the label is unusable (empty
	 * or title-forbidden characters) or the namespace is unknown.
	 */
	private function pageTitleFor( ClassicPageSpec $spec, string $label ): ?Title {
		$label = trim( $label );
		if ( $label === '' ) {
			return null;
		}
		try {
			$title = Title::makeTitle( $spec->namespace, $label );
		} catch ( \Throwable $e ) {
			return null;
		}
		return ( $title !== null && $title->getNamespace() === $spec->namespace && $title->isValid() )
			? $title
			: null;
	}

	private function linkPageToItem( Item $item, Title $title, string $label, UserIdentity $user ): void {
		if ( $item->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' ) ) {
			return;
		}
		$item->getSiteLinkList()->setNewSiteLink( 'wikibase', $title->getPrefixedText() );
		WikibaseRepo::getEntityStore()->saveEntity(
			$item,
			$this->msg( 'embeddablecontent-page-sitelink-edit-summary', [ $label ] ),
			$user,
			EDIT_UPDATE
		);
		// Also write the sitelink table synchronously (diff-based, a harmless
		// no-op when the entity save's secondary update already landed).
		WikibaseRepo::getStore()->newSiteLinkStore()->saveLinksOfItem( $item );
	}

	private function createClassicPage(
		ClassicPageSpec $spec,
		Title $title,
		array $record,
		string $label,
		UserIdentity $user
	): bool {
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$status = $page->doUserEditContent(
			new WikitextContent( $this->pageSkeleton( $spec, $record ) ),
			$user,
			$this->msg( 'embeddablecontent-page-edit-summary', [ $label ] ),
			EDIT_NEW
		);
		return $status->isOK();
	}

	/**
	 * The page skeleton: the template + the item description as an
	 * == Overview == placeholder. Only sections with content are rendered —
	 * no blank scaffolds.
	 *
	 * @param array<string,mixed> $record
	 */
	private function pageSkeleton( ClassicPageSpec $spec, array $record ): string {
		$body = '{{' . $spec->template . "}}\n\n";
		$overview = trim( (string)( $record['description'] ?? '' ) );
		if ( $overview !== '' ) {
			$body .= "== Overview ==\n\n{$overview}\n\n";
		}
		return $body;
	}

	private function msg( string $key, array $params ): string {
		return wfMessage( $key, ...$params )->inContentLanguage()->text();
	}
}
