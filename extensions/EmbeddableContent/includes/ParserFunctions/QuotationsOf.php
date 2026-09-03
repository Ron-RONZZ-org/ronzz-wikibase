<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Spec\QuotationLookup;
use MediaWiki\Parser\Parser;
use MediaWiki\SpecialPage\SpecialPage;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * `{{#quotations-of:}}` parser function — the "Quotations" auto-link on the
 * Source: pages (issue #79).
 *
 * Resolves the CURRENT page's sitelinked item (the same site-link store the
 * Sitelink tab and `{{#source-access:}}` use) — or, with an explicit id
 * (`{{#quotations-of:Q42}}`), the named item — counts its quotations via
 * WDQS and renders a complete table row when at least one exists:
 *
 *   | Quotations || N   (linked to Special:QuotationsOf/<Qid>)
 *
 * When the source has no quotations (or WDQS is unreachable) it returns ''
 * — the row disappears entirely, so a source page without quotations shows
 * no stray row (ParserFunctions' #if is not installed on the instance, so
 * the hiding lives in this function). The source item page is registered as
 * a parser-cache dependency; quotation creations/updates invalidate the
 * source's classic page explicitly (see the flow services).
 *
 * Returns WIKITEXT (not HTML): the row participates in the template's
 * normal parse.
 *
 * @license GPL-2.0-or-later
 */
final class QuotationsOf {

	/** Site id of the local sitelink group (also hardcoded in Hooks.php). */
	private const SITE_ID = 'wikibase';

	/**
	 * @param EmbeddableContentConfig $config injected via the hook closure
	 * @param Parser $parser
	 * @param mixed[] $args [optional explicit item id]
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function onQuotationsOf( EmbeddableContentConfig $config, Parser $parser, array $args ): array {
		$itemId = self::resolveItemId( $parser, $args );
		if ( $itemId === null ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}

		$quotations = QuotationLookup::findForSource( $config, $itemId->getSerialization() );
		// WDQS unreachable / config incomplete: hide the row (the listing
		// page itself shows an explicit "unavailable" notice).
		if ( $quotations === null || $quotations === [] ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}

		self::registerCacheDependency( $parser, $itemId );

		$count = count( $quotations );
		$link = SpecialPage::getTitleFor( 'QuotationsOf', $itemId->getSerialization() );
		// A complete wikitext table row (the cell + link). The surrounding
		// template table continues after the function's closing newline.
		return [
			'text' => "\n|-\n| Quotations || [[" . $link->getPrefixedText() . '|' . $count . ']]',
			'noparse' => false,
			'isHTML' => false,
		];
	}

	/**
	 * The source item: the first argument when it is a valid item id,
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
	 * Parser-cache dependency on the source item page (mirrors
	 * SourceAccess::registerCacheDependency): ParserOutput::addTemplate()
	 * makes RefreshLinksJob re-parse this page when the source item is
	 * edited. Quotation additions invalidate the page explicitly.
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
