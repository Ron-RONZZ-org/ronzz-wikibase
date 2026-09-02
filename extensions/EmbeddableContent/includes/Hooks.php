<?php

declare( strict_types = 1 );

namespace EmbeddableContent;

use EmbeddableContent\ParserFunctions\ContentPayload;
use EmbeddableContent\ParserFunctions\ItemImage;
use EmbeddableContent\ParserFunctions\SourceAccess;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\Parser;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\SpecialPage\SpecialPage;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\Property;
use Wikibase\Repo\WikibaseRepo;

/**
 * Entry-point hooks: entity-page gadget (copy embed / copy citation), the
 * Source: classic-page "Copy internal citation" button (sourcecite), the
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
			$out->addModules( 'ext.embeddableContent.entityconfirm' );
			return;
		}

		// AddPerson/UpdatePerson — the place-of-birth/death fields are OSM
		// search comboboxes (osm-places): osmsuggest.js wires them to
		// Nominatim (browser-first). isSpecial() covers the class-scoped
		// subpages too (Special:AddPerson/<token>/review/0).
		if ( $title->isSpecial( 'AddPerson' ) || $title->isSpecial( 'UpdatePerson' ) ) {
			$out->addModules( 'ext.embeddableContent.osmsuggest' );
		}

		// Source: classic pages — the "Copy internal citation" button
		// (sourcecite.js): a sitelinked source page gets a toolbar button
		// that copies the wikitext snippet `<ref>{{#cite:Q42}}</ref>` for
		// citing the item on a wiki page. The page → item id resolution is
		// server-side (the same site-link store the parser functions and
		// the Sitelink tab use) — no client API roundtrip. Only pages that
		// ARE linked to an item render the button (a /fr translation
		// subpage has no sitelink of its own).
		if ( defined( 'NS_SOURCE' ) && $title->getNamespace() === NS_SOURCE && $title->exists() ) {
			$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( 'wikibase', $title->getPrefixedText() );
			if ( $itemId !== null ) {
				$out->addJsConfigVars( 'wbInternalCiteItem', $itemId->getSerialization() );
				$out->addModules( 'ext.embeddableContent.sourcecite' );
			}
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

		// "Update basic information" button (the autofill-confirm-update
		// batch): items whose class has a Special:Update* counterpart link
		// to it — server-side class detection, no client API roundtrip.
		$updateUrl = self::updateUrlForItem( $entityId->getSerialization() );
		if ( $updateUrl !== null ) {
			$out->addJsConfigVars( 'wbUpdateBasicInfoUrl', $updateUrl );
			$out->addModules( 'ext.embeddableContent.updatebutton' );
		}

		$oembedUrl = SpecialPage::getTitleFor( 'Embed', 'oembed' )
			->getFullURL( [ 'url' => $title->getFullURL() ] );
		$out->addLink( [
			'rel' => 'alternate',
			'type' => 'application/json+oembed',
			'href' => $oembedUrl,
		] );
	}

	/**
	 * The Special:Update* URL for an item, or null when the item's class has
	 * no Update page (not part of the Add* vocabulary). Class → Update page
	 * mapping (all config-derived, instance-agnostic):
	 *  - any source class      → Special:UpdateSource (per-class detection)
	 *  - the person class      → Special:UpdatePerson
	 *  - the other agent classes → Special:UpdateCollective
	 *  - the FOSS class        → Special:UpdateSoftware
	 *  - the fictional class   → Special:UpdateFictionalCharacter
	 */
	private static function updateUrlForItem( string $itemId ): ?string {
		try {
			$config = MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' );
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( !$item instanceof Item ) {
			return null;
		}
		$classIds = [];
		$propertyId = new \Wikibase\DataModel\Entity\NumericPropertyId( $config->instanceOfPropertyId() );
		foreach ( $item->getStatements()->getByPropertyId( $propertyId ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof \Wikibase\DataModel\Entity\EntityIdValue ) {
				$classIds[] = $value->getEntityId()->getSerialization();
			}
		}
		if ( $classIds === [] ) {
			return null;
		}

		foreach ( $config->sourceClasses() as $id ) {
			if ( in_array( $id, $classIds, true ) ) {
				return SpecialPage::getTitleFor( 'UpdateSource', $itemId )->getFullURL();
			}
		}
		$agentClasses = $config->agentClasses();
		if ( isset( $agentClasses['person'] ) && in_array( $agentClasses['person'], $classIds, true ) ) {
			return SpecialPage::getTitleFor( 'UpdatePerson', $itemId )->getFullURL();
		}
		foreach ( $agentClasses as $key => $id ) {
			if ( $key !== 'person' && in_array( $id, $classIds, true ) ) {
				return SpecialPage::getTitleFor( 'UpdateCollective', $itemId )->getFullURL();
			}
		}
		foreach ( $config->fossClasses() as $id ) {
			if ( in_array( $id, $classIds, true ) ) {
				return SpecialPage::getTitleFor( 'UpdateSoftware', $itemId )->getFullURL();
			}
		}
		foreach ( $config->fictionalCharacterClasses() as $id ) {
			if ( in_array( $id, $classIds, true ) ) {
				return SpecialPage::getTitleFor( 'UpdateFictionalCharacter', $itemId )->getFullURL();
			}
		}
		return null;
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
	 * "Access" infobox cell, ADR docs/decisions/source-access-rendering.md)
	 * and `{{#item-image:}}` (the classic-page image/logo/portrait infobox
	 * cell, ADR docs/decisions/infobox-image-from-statement.md). The config
	 * service is fetched LAZILY inside the closures — constructing it at
	 * hook time would throw on every parse before the seed has emitted the
	 * config map (EmbeddableContentConfig::assertShape requires
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
		$parser->setFunctionHook( 'itemimage', static function ( Parser $parser, ...$args ) use ( $services ): array {
			return ItemImage::onItemImage(
				$services->get( 'EmbeddableContent.Config' ),
				$parser,
				$args
			);
		} );
		$parser->setFunctionHook( 'content', static function ( Parser $parser, ...$args ) use ( $services ): array {
			return ContentPayload::onContent(
				$services->get( 'EmbeddableContent.Config' ),
				$parser,
				$args
			);
		} );
	}
}
