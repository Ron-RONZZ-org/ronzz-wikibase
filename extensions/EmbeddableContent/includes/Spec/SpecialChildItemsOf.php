<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Special:ChildItemsOf — the child-item listing for one source item (the
 * quotation-listing analogue for child SOURCE classes): every item that
 * `part of` the given item and is classified under one of its class's child
 * classes (book → book excerpts, YouTube channel → YouTube videos), as an
 * always-live page (never a stored subpage that can go stale).
 *
 * URL: Special:ChildItemsOf/Q42. The listing is queried live from WDQS on
 * every load, grouped by child class (the rows carry the child class's
 * plural label), each child linking to its own page — its classic Source:
 * page when it has one (a YouTube video does), its Item page otherwise (a
 * book excerpt creates no classic page). A WDQS failure degrades to an
 * explicit "unavailable" notice, never a 500; a parent item with no child
 * classes (or no children) renders the "no child items" notice.
 *
 * The auto-link that reaches this page lives on the Source: pages: the
 * per-class Source templates carry `{{#child-items-of:}}`, which renders a
 * "{child class plural} | N" table row linked here when N ≥ 1.
 *
 * @license GPL-2.0-or-later
 */
class SpecialChildItemsOf extends SpecialPage {

	/** @var Item|null the parent item (set by execute) */
	private ?Item $parentItem = null;

	public function __construct(
		private readonly EmbeddableContentConfig $config
	) {
		parent::__construct( 'ChildItemsOf' );
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( 'ext.embeddableContent.embed' );

		$itemId = $this->itemIdFromSubPage( $subPage );
		if ( $itemId === null ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-childitemsof-badid' )->escaped() )
			);
			return;
		}
		$parentItem = $this->loadItem( $itemId );
		if ( !$parentItem instanceof Item ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-childitemsof-notfound', $itemId )->escaped() )
			);
			return;
		}
		$this->parentItem = $parentItem;

		$label = $this->itemLabel( $parentItem, $itemId );
		$this->getOutput()->setPageTitle(
			$this->msg( 'embeddablecontent-childitemsof-title', $label )->text()
		);
		$this->getOutput()->addHTML(
			$this->introHtml( $itemId, $label )
		);

		$children = ChildItemLookup::findForParent( $this->config, $itemId );
		if ( $children === null ) {
			$this->getOutput()->addHTML(
				Html::warningBox( $this->msg( 'embeddablecontent-childitemsof-unavailable' )->escaped() )
			);
			return;
		}
		if ( $children === [] ) {
			$this->getOutput()->addHTML(
				Html::rawElement( 'p', [], $this->msg( 'embeddablecontent-childitemsof-none' )->escaped() )
			);
			return;
		}

		$this->getOutput()->addHTML( $this->listHtml( $children ) );
	}

	/**
	 * The lead: "Child items of {label}" context + a back link to the
	 * parent's classic page (Source:xxx) when it is sitelinked.
	 */
	private function introHtml( string $itemId, string $label ): string {
		$back = '';
		if ( $this->parentItem->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' ) ) {
			$pageName = $this->parentItem->getSiteLinkList()->getBySiteId( 'wikibase' )->getPageName();
			$title = Title::newFromText( $pageName );
			if ( $title !== null && $title->exists() ) {
				$back = ' ' . $this->getLinkRenderer()->makeLink(
					$title,
					$this->msg( 'embeddablecontent-childitemsof-back', $title->getPrefixedText() )->text()
				);
			}
		}
		return Html::rawElement( 'p', [], $this->msg( 'embeddablecontent-childitemsof-intro' )->escaped() . $back );
	}

	/**
	 * Children grouped by child class key; one block per child class (the
	 * group heading is the child class's plural label), rows are the child
	 * item label linked to its own page.
	 *
	 * @param array<string,array<int,array{qid:string,label:string}>> $children
	 */
	private function listHtml( array $children ): string {
		$html = '';
		foreach ( $children as $childKey => $rows ) {
			$html .= Html::rawElement( 'h2', [],
				Html::element( 'span', [], $this->msg( 'embeddablecontent-childitems-label-' . $childKey )->text() )
			);
			$items = [];
			foreach ( $rows as $row ) {
				$items[] = Html::rawElement( 'li', [],
					$this->childLink( $row['qid'], $row['label'] )
				);
			}
			$html .= Html::rawElement( 'ul', [], implode( "\n", $items ) );
		}
		return $html;
	}

	/**
	 * A link to the child item's own page: the sitelinked classic page when
	 * it exists (a YouTube video has a Source: page), the Item page
	 * otherwise (a book excerpt creates no classic page). Label fallback:
	 * the en label, then the item id.
	 */
	private function childLink( string $qid, string $label ): string {
		try {
			$link = WikibaseRepo::getStore()->newSiteLinkStore()->getLinkForItemId( new ItemId( $qid ) );
			$pageName = (string)( $link['pageName'] ?? '' );
			$title = Title::newFromText( $pageName );
			if ( $title !== null && $title->exists() ) {
				return $this->getLinkRenderer()->makeLink( $title, $label !== '' ? $label : $qid );
			}
		} catch ( \Throwable $e ) {
			// fall through to the Item link
		}
		$itemTitle = WikibaseRepo::getEntityTitleStoreLookup()->getTitleForId( new ItemId( $qid ) );
		if ( $itemTitle !== null ) {
			return $this->getLinkRenderer()->makeLink( $itemTitle, $label !== '' ? $label : $qid );
		}
		return htmlspecialchars( $qid );
	}

	private function itemIdFromSubPage( $subPage ): ?string {
		if ( !is_string( $subPage ) || trim( $subPage ) === '' ) {
			return null;
		}
		try {
			$id = WikibaseRepo::getEntityIdParser()->parse( trim( $subPage ) );
			return $id instanceof ItemId ? $id->getSerialization() : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function loadItem( string $itemId ): ?Item {
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
			return $entity instanceof Item ? $entity : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** The parent label for the heading (config fallback languages first). */
	private function itemLabel( Item $item, string $itemId ): string {
		$labels = $item->getLabels()->toTextArray();
		if ( $labels !== [] ) {
			foreach ( $this->config->fallbackLanguages() as $language ) {
				if ( isset( $labels[$language] ) ) {
					return $labels[$language];
				}
			}
			return (string)reset( $labels );
		}
		return $itemId;
	}

	/** @inheritDoc */
	public function getDescription() {
		return $this->msg( 'special-childitemsof' );
	}

	protected function getGroupName(): string {
		return 'wikibase';
	}
}
