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
 * `{{#quotations-by:}}` parser function — the "Quotations" auto-link on the
 * Person: pages (the `{{#quotations-of:}}` counterpart, but linked through
 * the quotation's `attributed to` statement instead of its `source`).
 *
 * Resolves the CURRENT page's sitelinked item — or, with an explicit id
 * (`{{#quotations-by:Q42}}`), the named item — counts its quotations via
 * WDQS and renders a complete table row when at least one exists:
 *
 *   | Quotations || N   (linked to Special:QuotationsOf/<Qid>/author)
 *
 * When the author has no quotations (or WDQS is unreachable) it returns '' —
 * the row disappears entirely (ParserFunctions' #if is not installed on the
 * instance, so the hiding lives in this function). The author item page is
 * registered as a parser-cache dependency; quotation creations/updates
 * invalidate the author's classic page explicitly (see the flow services).
 *
 * Returns WIKITEXT (not HTML): the row participates in the template's
 * normal parse.
 *
 * @license GPL-2.0-or-later
 */
final class QuotationsBy {

	/** Site id of the local sitelink group (also hardcoded in Hooks.php). */
	private const SITE_ID = 'wikibase';

	/**
	 * @param EmbeddableContentConfig $config injected via the hook closure
	 * @param Parser $parser
	 * @param mixed[] $args [optional explicit item id]
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function onQuotationsBy( EmbeddableContentConfig $config, Parser $parser, array $args ): array {
		$itemId = self::resolveItemId( $parser, $args );
		if ( $itemId === null ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}

		$quotations = QuotationLookup::findByAuthor( $config, $itemId->getSerialization() );
		// WDQS unreachable / config incomplete: hide the row (the listing
		// page itself shows an explicit "unavailable" notice).
		if ( $quotations === null || $quotations === [] ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}

		self::registerCacheDependency( $parser, $itemId );

		$count = count( $quotations );
		$link = SpecialPage::getTitleFor( 'QuotationsOf', $itemId->getSerialization() . '/author' );
		return [
			'text' => "\n|-\n| Quotations || [[" . $link->getPrefixedText() . '|' . $count . ']]',
			'noparse' => false,
			'isHTML' => false,
		];
	}

	/**
	 * The author item: the first argument when it is a valid item id,
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
	 * Parser-cache dependency on the author item page (mirrors
	 * QuotationsOf::registerCacheDependency): ParserOutput::addTemplate()
	 * makes RefreshLinksJob re-parse this page when the author item is
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
