<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Spec\ChildItemLookup;
use MediaWiki\Parser\Parser;
use MediaWiki\SpecialPage\SpecialPage;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * `{{#child-items-of:}}` parser function — the child-items auto-link on the
 * Source: pages (book → book excerpts, YouTube channel → YouTube videos).
 *
 * Resolves the CURRENT page's sitelinked item — or, with an explicit id
 * (`{{#child-items-of:Q42}}`), the named item — counts its child items via
 * WDQS (one row per child class with children) and renders a complete
 * table row when at least one exists:
 *
 *   | Book excerpts || N   (linked to Special:ChildItemsOf/<Qid>)
 *
 * When the item has no children (or WDQS is unreachable) it returns '' —
 * the row disappears entirely (ParserFunctions' #if is not installed on
 * the instance, so the hiding lives in this function). The parent item is
 * registered as a parser-cache dependency; child-item creations/updates
 * invalidate the parent's classic page explicitly (see the flow services).
 *
 * Returns WIKITEXT (not HTML): the row participates in the template's
 * normal parse.
 *
 * @license GPL-2.0-or-later
 */
final class ChildItemsOf {

	/** Site id of the local sitelink group (also hardcoded in Hooks.php). */
	private const SITE_ID = 'wikibase';

	/**
	 * @param EmbeddableContentConfig $config injected via the hook closure
	 * @param Parser $parser
	 * @param mixed[] $args [optional explicit item id]
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function onChildItemsOf( EmbeddableContentConfig $config, Parser $parser, array $args ): array {
		$itemId = self::resolveItemId( $parser, $args );
		if ( $itemId === null ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}

		$children = ChildItemLookup::findForParent( $config, $itemId->getSerialization() );
		// WDQS unreachable / config incomplete / no child classes: hide the
		// row (the listing page itself shows an explicit notice).
		if ( $children === null || $children === [] ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}

		self::registerCacheDependency( $parser, $itemId );

		$link = SpecialPage::getTitleFor( 'ChildItemsOf', $itemId->getSerialization() );
		$rows = '';
		foreach ( $children as $childKey => $childRows ) {
			$count = count( $childRows );
			// The row label is the child class's PLURAL label message, e.g.
			// "Book excerpts" (the child items of a book). Falls back to the
			// class-key text when the message is missing (never a fatal).
			$text = self::rowLabel( $parser, $childKey );
			$rows .= "\n|-\n| " . $text . ' || [[' . $link->getPrefixedText() . '|' . $count . ']]';
		}
		return [
			'text' => $rows,
			'noparse' => false,
			'isHTML' => false,
		];
	}

	/**
	 * The plural child-class row label, localized: the parser's function
	 * language first, then the configured fallbacks (English last). Missing
	 * message → the raw child class key (a config without the label degrades
	 * to the key text, never an error).
	 */
	private static function rowLabel( Parser $parser, string $childKey ): string {
		$messageKey = 'embeddablecontent-childitems-label-' . $childKey;
		$langs = [];
		$functionLang = $parser->getFunctionLang();
		if ( $functionLang !== null ) {
			$langs[] = $functionLang->getCode();
		}
		$langs = array_values( array_unique( array_merge( $langs, [ 'en' ] ) ) );
		foreach ( $langs as $lang ) {
			$message = wfMessage( $messageKey )->inLanguage( $lang );
			if ( $message->exists() && $message->plain() !== '' ) {
				return $message->text();
			}
		}
		return $childKey;
	}

	/**
	 * The parent item: the first argument when it is a valid item id,
	 * otherwise the current page's sitelinked item (template use).
	 *
	 * @param mixed[] $args
	 */
	private static function resolveItemId( Parser $parser, array $args ): ?ItemId {
		$explicit = trim( (string)( $args[0] ?? '' ) );
		if ( $explicit !== '' ) {
			try {
				$id = WikibaseRepo::getEntityIdParser()->parse( $explicit );
				return $id instanceof ItemId ? $id : null;
			} catch ( \Throwable $e ) {
				return null;
			}
		}
		$title = $parser->getTitle();
		if ( $title === null || !$title->exists() || !$title->isContentPage() ) {
			return null;
		}
		return WikibaseRepo::getStore()->newSiteLinkStore()
			->getItemIdForLink( self::SITE_ID, $title->getPrefixedText() );
	}

	/**
	 * Parser-cache dependency on the parent item page (mirrors
	 * QuotationsOf::registerCacheDependency): ParserOutput::addTemplate()
	 * makes RefreshLinksJob re-parse this page when the parent item is
	 * edited. Child additions invalidate the page explicitly.
	 */
	private static function registerCacheDependency( Parser $parser, EntityId $itemId ): void {
		$services = \MediaWiki\MediaWikiServices::getInstance();
		$title = WikibaseRepo::getEntityTitleStoreLookup( $services )->getTitleForId( $itemId );
		if ( $title === null || !$title->exists() ) {
			return;
		}
		$revId = WikibaseRepo::getEntityRevisionLookup( $services )->getLatestRevisionId( $itemId ) ?? 0;
		$parser->getOutput()->addTemplate( $title, $title->getArticleID(), $revId );
	}
}
