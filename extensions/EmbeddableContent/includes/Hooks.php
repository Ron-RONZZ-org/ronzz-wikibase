<?php

declare( strict_types = 1 );

namespace EmbeddableContent;

use EmbeddableContent\ParserFunctions\SourceAccess;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\Parser;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\SpecialPage\SpecialPage;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\Property;
use Wikibase\Repo\WikibaseRepo;

/**
 * Entry-point hooks: entity-page gadget (copy embed / copy citation), the
 * oEmbed discovery <link> on item pages (issue #6 §4.3, §4.4), and the
 * page↔item Sitelink tab (issue #7 follow-up: red = not linked → popup to
 * link by label search or Q-id; blue = linked → the Item page).
 *
 * @license GPL-2.0-or-later
 */
class Hooks {

	/**
	 * Sitelink tab next to Page/Discussion on every content page: blue when
	 * the page is sitelinked to an item (href → Item page), red when not
	 * (href → Special:NewItem prefilled as a no-JS fallback; the JS module
	 * intercepts the click and opens the link popup instead).
	 *
	 * Hook handler name: MediaWiki maps the hook name SkinTemplateNavigation::
	 * Universal to the handler method onSkinTemplateNavigation__Universal
	 * (:: → __).
	 */
	public static function onSkinTemplateNavigation__Universal( SkinTemplate $skin, array &$links ): void {
		$title = $skin->getTitle();
		if ( $title === null || !$title->exists() || !$title->isContentPage() ) {
			return;
		}

		// Entity pages (Item:/Property:) are the semantic entities themselves
		// — the tab makes no sense there.
		$namespaceLookup = WikibaseRepo::getEntityNamespaceLookup();
		foreach ( [ Item::ENTITY_TYPE, Property::ENTITY_TYPE ] as $entityType ) {
			$namespace = $namespaceLookup->getEntityNamespace( $entityType );
			if ( $namespace !== false && $title->getNamespace() === $namespace ) {
				return;
			}
		}

		$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
			->getItemIdForLink( 'wikibase', $title->getPrefixedText() );

		if ( $itemId !== null ) {
			$tab = [
				'text' => $skin->msg( 'embeddablecontent-sitelink-tab' )->text(),
				'class' => 'ca-sitelink is-set',
				'href' => SpecialPage::getTitleFor( 'EntityPage', $itemId->getSerialization() )->getFullURL(),
				'title' => $skin->msg( 'embeddablecontent-sitelink-tab-set', $itemId->getSerialization() )->text(),
			];
		} else {
			$tab = [
				'text' => $skin->msg( 'embeddablecontent-sitelink-tab' )->text(),
				'class' => 'ca-sitelink needs-set',
				'href' => SpecialPage::getTitleFor( 'NewItem' )->getFullURL( [
					'site' => 'wikibase',
					'page' => $title->getPrefixedText(),
				] ),
				'title' => $skin->msg( 'embeddablecontent-sitelink-tab-unset' )->text(),
			];
		}

		// MW 1.46 renamed the namespace tab menu to `associated-pages` and
		// deprecates `namespaces`; the fallback copies namespaces → 
		// associated-pages only when both have equal counts (adding to one
		// key breaks the fallback). Set BOTH so every skin sees the tab.
		$links['namespaces']['sitelink'] = $tab;
		$links['associated-pages']['sitelink'] = $tab;

		$skin->getOutput()->addModules( 'ext.embeddableContent.sitelinktab' );
	}

	public static function onBeforePageDisplay( OutputPage $out, $skin ): void {
		$title = $out->getTitle();
		if ( $title === null ) {
			return;
		}

		// Special:Upload — the semantic license combobox needs the entity
		// autocomplete (native formatting, same as the Add* pages) and the
		// URL validate button + 429 blob fallback. The wiring span is
		// rendered by UploadHooks::onUploadFormSourceDescriptors; these
		// modules make it functional. Without them the combobox is a plain
		// OOUI widget and the validate button never renders.
		if ( $title->isSpecial( 'Upload' ) ) {
			$out->addModules( 'ext.embeddableContent.entitysuggest' );
			$out->addModules( 'ext.embeddableContent.uploadmeta' );
			return;
		}

		$namespaceLookup = WikibaseRepo::getEntityNamespaceLookup();
		$itemNamespace = $namespaceLookup->getEntityNamespace( Item::ENTITY_TYPE );
		if ( $itemNamespace === false || $title->getNamespace() !== $itemNamespace ) {
			return;
		}

		$entityId = self::parseItemId( $title->getText() );
		if ( $entityId === null ) {
			return;
		}

		$out->addModules( 'ext.embeddableContent.gadget' );

		$oembedUrl = SpecialPage::getTitleFor( 'Embed', 'oembed' )
			->getFullURL( [ 'url' => $title->getFullURL() ] );
		$out->addLink( [
			'rel' => 'alternate',
			'type' => 'application/json+oembed',
			'href' => $oembedUrl,
		] );
	}

	private static function parseItemId( string $text ): ?\Wikibase\DataModel\Entity\ItemId {
		try {
			$id = WikibaseRepo::getEntityIdParser()->parse( $text );
			return $id instanceof \Wikibase\DataModel\Entity\ItemId ? $id : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Register the `{{#source-access:}}` parser function (the Source: page
	 * "Access" infobox cell, ADR docs/decisions/source-access-rendering.md).
	 * The config service is fetched LAZILY inside the closure — constructing
	 * it at hook time would throw on every parse before the seed has emitted
	 * the config map (EmbeddableContentConfig::assertShape requires
	 * `instanceOf`), breaking the WBS bootstrap's main-page insert.
	 */
	public static function onParserFirstCallInit( Parser $parser ): void {
		$services = MediaWikiServices::getInstance();
		$parser->setFunctionHook( 'sourceaccess', static function ( Parser $parser, ...$args ) use ( $services ): array {
			return SourceAccess::onSourceAccess(
				$services->get( 'EmbeddableContent.Config' ),
				$parser,
				$args
			);
		} );
	}
}
