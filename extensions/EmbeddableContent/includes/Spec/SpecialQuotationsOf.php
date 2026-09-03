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
 * Special:QuotationsOf — the quotation listing for one source item (issue
 * #79): every quotation item whose `source` statement points at the given
 * item, as an always-live page (never a stored subpage that can go stale).
 *
 * URL: Special:QuotationsOf/Q42. The listing is queried live from WDQS on
 * every load (Special pages are never parser-cached), decoded with
 * PayloadCodec — the quotation text shows with real multi-line content —
 * and links back to the source's classic page when it exists. A WDQS
 * failure degrades to an explicit "unavailable" notice, never a 500; an
 * empty result renders the "no quotations" notice.
 *
 * The auto-link that reaches this page lives on the Source: pages: the
 * per-class Source templates carry `{{#quotations-of:}}`, which renders a
 * "Quotations | N" table row linked here when N ≥ 1.
 *
 * @license GPL-2.0-or-later
 */
class SpecialQuotationsOf extends SpecialPage {

	/** @var Item|null the source item (set by execute) */
	private ?Item $sourceItem = null;

	public function __construct(
		private readonly EmbeddableContentConfig $config
	) {
		parent::__construct( 'QuotationsOf' );
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( 'ext.embeddableContent.embed' );

		$itemId = $this->itemIdFromSubPage( $subPage );
		if ( $itemId === null ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-quotationsof-badid' )->escaped() )
			);
			return;
		}
		$sourceItem = $this->loadItem( $itemId );
		if ( !$sourceItem instanceof Item ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-quotationsof-notfound', $itemId )->escaped() )
			);
			return;
		}
		$this->sourceItem = $sourceItem;

		$label = $this->itemLabel( $sourceItem, $itemId );
		$this->getOutput()->setPageTitle(
			$this->msg( 'embeddablecontent-quotationsof-title', $label )->text()
		);
		$this->getOutput()->addHTML(
			$this->introHtml( $itemId, $label )
		);

		$quotations = QuotationLookup::findForSource( $this->config, $itemId );
		if ( $quotations === null ) {
			$this->getOutput()->addHTML(
				Html::warningBox( $this->msg( 'embeddablecontent-quotationsof-unavailable' )->escaped() )
			);
			return;
		}
		if ( $quotations === [] ) {
			$this->getOutput()->addHTML(
				Html::rawElement( 'p', [], $this->msg( 'embeddablecontent-quotationsof-none' )->escaped() )
			);
			return;
		}

		$this->getOutput()->addHTML( $this->listHtml( $quotations ) );
	}

	/**
	 * The lead: "Quotations of {label}" context + a back link to the
	 * source's classic page (Source:xxx) when it is sitelinked.
	 */
	private function introHtml( string $itemId, string $label ): string {
		$back = '';
		if ( $this->sourceItem->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' ) ) {
			$pageName = $this->sourceItem->getSiteLinkList()->getBySiteId( 'wikibase' )->getPageName();
			$title = Title::newFromText( $pageName );
			if ( $title !== null && $title->exists() ) {
				$back = ' ' . $this->getLinkRenderer()->makeLink(
					$title,
					$this->msg( 'embeddablecontent-quotationsof-back', $title->getPrefixedText() )->text()
				);
			}
		}
		return Html::rawElement( 'p', [], $this->msg( 'embeddablecontent-quotationsof-intro' )->escaped() . $back );
	}

	/**
	 * @param array<int,array{qid:string,content:string,label:string}> $quotations
	 */
	private function listHtml( array $quotations ): string {
		$items = [];
		foreach ( $quotations as $row ) {
			$text = $row['content'] !== '' ? $row['content'] : ( $row['label'] !== '' ? $row['label'] : $row['qid'] );
			$itemTitle = WikibaseRepo::getEntityTitleStoreLookup()->getTitleForId( new ItemId( $row['qid'] ) );
			$items[] = Html::rawElement( 'li', [],
				Html::element( 'span', [ 'class' => 'wb-quotation-text' ], $text )
				. ' ' . Html::element( 'a',
					[ 'href' => $itemTitle ? $itemTitle->getFullURL() : '#', 'title' => $row['qid'] ],
					'[' . $row['qid'] . ']'
				)
			);
		}
		return Html::rawElement( 'ul', [ 'class' => 'wb-quotations-of-list' ], implode( "\n", $items ) );
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

	/** The source label for the heading (config fallback languages first). */
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
		return $this->msg( 'special-quotationsof' );
	}

	protected function getGroupName(): string {
		return 'wikibase';
	}
}
