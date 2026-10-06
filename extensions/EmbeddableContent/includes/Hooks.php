<?php

declare( strict_types = 1 );

namespace EmbeddableContent;

use EmbeddableContent\ParserFunctions\ChildItemsOf;
use EmbeddableContent\ParserFunctions\ContentPayload;
use EmbeddableContent\ParserFunctions\ItemImage;
use EmbeddableContent\ParserFunctions\QuotationsOf;
use EmbeddableContent\ParserFunctions\SourceAccess;
use EmbeddableContent\Spec\EntityLabelText;
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

	/**
	 * The item↔page auto-link pair:
	 *  - Special:NewItem auto-creates a Main-namespace sitelinked classic page
	 *    for the item it just created (Flow/NewItemPageCreator) — gated to the
	 *    Special:NewItem request, so the Add* flows (which create their own
	 *    per-kind pages) and API/script item creation are untouched;
	 *  - a NEW Main-namespace classic page auto-creates (or reuses) its
	 *    sitelinked item (Flow/PageItemCreator) — the reverse direction, and
	 *    the backfill for pages that predate the item.
	 */
	public static function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	): void {
		// The params are deliberately UNTYPED: MW 1.46's PageSaveCompleteHook
		// interface declares them untyped, and entity saves call the hook with
		// null for $summary/$revisionRecord/$editResult (only $wikiPage, $user
		// and $flags are populated) — typed hints throw a TypeError and the
		// handler never runs.
		\EmbeddableContent\Flow\NewItemPageCreator::handle(
			$wikiPage,
			$user,
			$flags,
			\MediaWiki\Context\RequestContext::getMain()->getTitle()
		);
		\EmbeddableContent\Flow\PageItemCreator::handle(
			$wikiPage,
			$user,
			$flags
		);
	}

	/**
	 * Special:Upload success hand-off (2026-09 UX batch). The form's two
	 * extra controls ride the File: page redirect as one-shot query params,
	 * because the destination File: page is where the FINAL file name is
	 * known:
	 *  - the "Copy internal embed code" checkbox → ?wbuploadcopy=1 (the
	 *    File page copies [[File:xxx]]);
	 *  - the "Submit and upload another image from same author" button
	 *    (wpUpload=another) → ?wbanother=1 + the license/author/license-info
	 *    values, so the File page (opened in a new tab by uploadform.js)
	 *    sends the opener back to a fresh, prefilled upload form.
	 *
	 * Fires for every redirect; acts only on a Special:Upload form submission
	 * (identified by the wpUpload marker, present only on that form — the
	 * context title is not guaranteed during output()).
	 *
	 * @param OutputPage $out
	 * @param string &$redirect
	 * @param string &$code
	 */
	public static function onBeforePageRedirect( $out, &$redirect, &$code ): void {
		if ( !$out instanceof OutputPage ) {
			return;
		}
		$request = \MediaWiki\Context\RequestContext::getMain()->getRequest();
		if ( !$request->getCheck( 'wpUpload' ) ) {
			return;
		}
		$params = [];
		if ( $request->getCheck( 'wpUploadCopyEmbed' ) ) {
			$params['wbuploadcopy'] = '1';
		}
		if ( $request->getVal( 'wpUpload' ) === 'another' ) {
			$params['wbanother'] = '1';
			$params['wblicense'] = (string)$request->getVal( 'wpLicense', '' );
			$params['wbauthor'] = (string)$request->getVal( 'wpUploadAuthor', '' );
			$params['wblicenseinfo'] = (string)$request->getVal( 'wpUploadLicenseInfo', '' );
		}
		if ( $params === [] ) {
			return;
		}
		$redirect = wfAppendQuery( $redirect, $params );
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
			// Source-field gating, the empty author/license warning and the
			// "upload another" new-tab behaviour (2026-09 UX batch).
			$out->addModules( 'ext.embeddableContent.uploadform' );
			// Local-file preview + client-side resize + extension
			// auto-correction (the same module the Add* portrait/logo
			// sections load).
			$out->addModules( 'ext.embeddableContent.uploadimage' );
			return;
		}

		// AddPerson/UpdatePerson — the place-of-birth/death fields are OSM
		// search comboboxes (osm-places): osmsuggest.js wires them to
		// Nominatim (browser-first). The AddSource/UpdateSource legal classes
		// reuse the same combobox for the territorial-jurisdiction field.
		// isSpecial() covers the class-scoped subpages too
		// (Special:AddSource/legalCase/manual).
		if ( $title->isSpecial( 'AddPerson' ) || $title->isSpecial( 'UpdatePerson' )
			|| $title->isSpecial( 'AddSource' ) || $title->isSpecial( 'UpdateSource' )
		) {
			$out->addModules( 'ext.embeddableContent.osmsuggest' );
		}

		// File: pages — the "Copy internal embed code" / "Copy direct link"
		// buttons rendered inline to the right of the file-name title, plus
		// the upload hand-off (a File: page that is a Special:Upload
		// destination carries ?wbuploadcopy=1 / ?wbanother=1). The media URL
		// and the page name ride JS config vars, so the module needs no API
		// roundtrip. Non-files (redlinks) render nothing.
		if ( $title->getNamespace() === NS_FILE ) {
			$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title );
			if ( $file !== false ) {
				$out->addJsConfigVars( 'wbFileName', $title->getText() );
				$out->addJsConfigVars( 'wbFileUrl', $file->getFullUrl() );
				$out->addModules( 'ext.embeddableContent.filepage' );
			}
			return;
		}

		// Classic per-kind pages (Source: / FOSS: / Person: / Collective: /
		// Software:) carry the SAME action toolbar as their item's Item:
		// page — the "Update basic information" / "Edit content" button,
		// the "Copy internal citation" button (source classes) and the
		// embed/citation gadget. The page → item id comes from the
		// site-link store (the same resolution the parser functions and the
		// Sitelink tab use) — no client API roundtrip. A page without a
		// sitelink (a /fr translation subpage) renders no toolbar.
		if ( $title->exists() && self::isClassicEntityNamespace( $title->getNamespace() ) ) {
			$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( 'wikibase', $title->getPrefixedText() );
			if ( $itemId !== null ) {
				// On a classic page the "Update basic information" flow must
				// return the user HERE (the classic page), not to Item: —
				// the frompage marker rides the Update form.
				self::wireItemToolbar( $out, $itemId->getSerialization(), true );
				self::wireMentionButton( $out, $title, $itemId->getSerialization() );
			}
			return;
		}

		// Ordinary content pages (Main, Help, Cheatsheets, HowItWorks, …) —
		// the "Copy internal reference" button, rendered inline next to the
		// title (the File: page copy-button pattern). Entity pages
		// (Item:/Property:), Special pages, File: pages and the per-kind
		// classic namespaces are handled above; only real, existing content
		// pages qualify.
		if ( $title->exists() && $title->isContentPage()
			&& !self::isEntityNamespace( $title->getNamespace() )
		) {
			$out->addJsConfigVars( 'wbReferencePageName', $title->getPrefixedText() );
			$out->addModules( 'ext.embeddableContent.contentpagetoolbar' );
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

		self::wireItemToolbar( $out, $entityId->getSerialization() );

		// Content items (quotation / math / code-snippet) carry NO classic
		// page — render their embed fragment directly below the toolbar, so
		// the Item page shows the actual content next to its statements.
		if ( self::contentKindForItem( $entityId->getSerialization() ) !== null ) {
			$out->addJsConfigVars( 'wbContentPreviewItem', $entityId->getSerialization() );
			$out->addModules( 'ext.embeddableContent.contentpreview' );
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
	 * The classic-page namespaces that carry an item's action toolbar —
	 * the Add* flows' per-kind pages: FOSS / Person / Source / Collective /
	 * Software. The constants are defined by the instance LocalSettings
	 * (dev config + production); the defined() guards keep the hook safe on
	 * a wiki that loads the extension without them.
	 */
	private static function isClassicEntityNamespace( int $namespace ): bool {
		foreach ( [ 'NS_FOSS', 'NS_PERSON', 'NS_SOURCE', 'NS_COLLECTIVE', 'NS_SOFTWARE' ] as $constant ) {
			if ( defined( $constant ) && $namespace === constant( $constant ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the namespace is a Wikibase entity namespace (Item:/Property:).
	 * Entity pages have their own toolbar wiring (or none); they must not get
	 * the classic content-page toolbar.
	 */
	private static function isEntityNamespace( int $namespace ): bool {
		$lookup = WikibaseRepo::getEntityNamespaceLookup();
		foreach ( [ Item::ENTITY_TYPE, Property::ENTITY_TYPE ] as $entityType ) {
			$entityNamespace = $lookup->getEntityNamespace( $entityType );
			if ( $entityNamespace !== false && $namespace === $entityNamespace ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The item action toolbar shared by the Item: pages and the classic
	 * per-kind pages (feature parity):
	 *  - the embed/citation gadget (the item id rides wbEmbedItem, because
	 *    on a classic page wgTitle is the page title, not the Q-id);
	 *  - the "Update basic information" / "Edit content" button when the
	 *    item's class has a Special:Update* counterpart (server-side class
	 *    detection, no client API roundtrip);
	 *  - the "Copy internal citation" button for source-class items.
	 */
	private static function wireItemToolbar( OutputPage $out, string $itemId, bool $fromClassicPage = false ): void {
		$out->addJsConfigVars( 'wbEmbedItem', $itemId );
		$out->addModules( 'ext.embeddableContent.gadget' );

		$updateTarget = self::updateTargetForItem( $itemId, $fromClassicPage );
		if ( $updateTarget !== null ) {
			$out->addJsConfigVars( 'wbUpdateBasicInfoUrl', $updateTarget['url'] );
			$out->addJsConfigVars( 'wbUpdateBasicInfoLabel', $updateTarget['messageKey'] );
			$out->addModules( 'ext.embeddableContent.updatebutton' );
		}

		if ( self::isSourceClassItem( $itemId ) ) {
			$out->addJsConfigVars( 'wbInternalCiteItem', $itemId );
			$out->addModules( 'ext.embeddableContent.sourcecite' );
		}
	}

	/**
	 * The "Copy internal mention" button on the classic per-kind pages: it
	 * copies a piped internal link to the page, `[[<page>|<item label>]]`
	 * (italic on Source: pages, `''[[Source:Beloved (Book)|Beloved]]''`).
	 * Rendered in the shared toolbar; resources/mention.js places it to the
	 * right of the "Copy internal citation" button on source pages.
	 *
	 * The label is the item's English label (the page title basis), falling
	 * back to the page text when the item has none. Any trailing
	 * class-disambiguation parenthetical (" (Book)") is dropped from the
	 * DISPLAY label only — the link target stays the full page title.
	 */
	private static function wireMentionButton(
		OutputPage $out,
		\MediaWiki\Title\Title $title,
		string $itemId
	): void {
		$out->addJsConfigVars( 'wbMentionLink', $title->getPrefixedText() );
		// The display label drops the class-disambiguation suffix the
		// AddSource flow appends ("Méditations poétiques (Book)" → "Méditations
		// poétiques"); the LINK still targets the full page title.
		$out->addJsConfigVars(
			'wbMentionLabel',
			\EmbeddableContent\Spec\LabelSanitizer::stripParentheticalSuffix(
				self::itemLabel( $itemId ) ?? $title->getText()
			)
		);
		$out->addJsConfigVars(
			'wbMentionItalic',
			defined( 'NS_SOURCE' ) && $title->getNamespace() === NS_SOURCE
		);
		$out->addModules( 'ext.embeddableContent.mention' );
	}

	/**
	 * The item's label (English preferred, then any language), or null when
	 * it has none. Uses the guarded EntityLabelText — the AddSource language
	 * field can leave an item with a non-English label only, and an unguarded
	 * getByLanguage( 'en' ) would 500 the classic-page render.
	 */
	private static function itemLabel( string $itemId ): ?string {
		try {
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $item instanceof Item ? EntityLabelText::of( $item ) : null;
	}

	/**
	 * Whether the item is classified under one of the configured source
	 * classes (book, scholarly article, website, …). Server-side class
	 * detection over the instance-of statements — the same scan
	 * updateTargetForItem performs, extracted so the sourcecite wiring can
	 * reuse it on Item pages.
	 */
	private static function isSourceClassItem( string $itemId ): bool {
		try {
			$config = MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' );
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( !$item instanceof Item ) {
			return false;
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
			return false;
		}
		foreach ( $config->sourceClasses() as $id ) {
			if ( in_array( $id, $classIds, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The Special:Update* target for an item, or null when the item's class
	 * has no Update page (not part of the Add* vocabulary). Class → Update
	 * page mapping (all config-derived, instance-agnostic):
	 *  - any source class      → Special:UpdateSource (per-class detection)
	 *  - the person class      → Special:UpdatePerson
	 *  - the other agent classes → Special:UpdateCollective
	 *  - the FOSS class        → Special:UpdateSoftware
	 *  - the fictional class   → Special:UpdateFictionalCharacter
	 *  - the content classes (quotation/math/code-snippet) → the
	 *    Special:UpdateQuotation/Math/CodeSnippet pages ("Edit content").
	 *
	 * @return array{url:string,messageKey:string}|null
	 */
	private static function updateTargetForItem( string $itemId, bool $fromClassicPage = false ): ?array {
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

		// When the button was clicked on a classic page (Person:/Source:/…)
		// the Update flow returns the user to that page: the frompage marker
		// rides the Update URL and the form.
		$query = $fromClassicPage ? [ 'frompage' => '1' ] : [];

		// Content classes (quotation / math / code-snippet) — "Edit
		// content" pages (issue #80). A config without the content
		// vocabulary ('classes' map) degrades to no content button — it
		// must never break the semantic-entity mappings below.
		$contentTarget = self::contentUpdateTarget( $itemId, $config, $classIds, $query );
		if ( $contentTarget !== null ) {
			return $contentTarget;
		}

		foreach ( $config->sourceClasses() as $id ) {
			if ( in_array( $id, $classIds, true ) ) {
				return [
					'url' => SpecialPage::getTitleFor( 'UpdateSource', $itemId )->getFullURL( $query ),
					'messageKey' => 'embeddablecontent-update-button',
				];
			}
		}
		$agentClasses = $config->agentClasses();
		if ( isset( $agentClasses['person'] ) && in_array( $agentClasses['person'], $classIds, true ) ) {
			return [
				'url' => SpecialPage::getTitleFor( 'UpdatePerson', $itemId )->getFullURL( $query ),
				'messageKey' => 'embeddablecontent-update-button',
			];
		}
		foreach ( $agentClasses as $key => $id ) {
			if ( $key !== 'person' && in_array( $id, $classIds, true ) ) {
				return [
					'url' => SpecialPage::getTitleFor( 'UpdateCollective', $itemId )->getFullURL( $query ),
					'messageKey' => 'embeddablecontent-update-button',
				];
			}
		}
		foreach ( $config->fossClasses() as $id ) {
			if ( in_array( $id, $classIds, true ) ) {
				return [
					'url' => SpecialPage::getTitleFor( 'UpdateSoftware', $itemId )->getFullURL( $query ),
					'messageKey' => 'embeddablecontent-update-button',
				];
			}
		}
		foreach ( $config->fictionalCharacterClasses() as $id ) {
			if ( in_array( $id, $classIds, true ) ) {
				return [
					'url' => SpecialPage::getTitleFor( 'UpdateFictionalCharacter', $itemId )->getFullURL( $query ),
					'messageKey' => 'embeddablecontent-update-button',
				];
			}
		}
		return null;
	}

	/**
	 * The "Edit content" update target for an item whose instance-of
	 * classes include a content class (quotation / math / code-snippet).
	 *
	 * @return array{url:string,messageKey:string}|null
	 */
	/**
	 * The content kind (quotation | math | code) an item is classified
	 * under, or null when it is not a content item. Used to decide whether
	 * the Item page renders the embedded-content preview.
	 */
	private static function contentKindForItem( string $itemId ): ?string {
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
		foreach ( $config->classIds() as $kind => $classId ) {
			if ( in_array( $classId, $classIds, true ) ) {
				return $kind;
			}
		}
		return null;
	}

	/**
	 * The "Edit content" update target for an item whose instance-of
	 * classes include a content class (quotation / math / code-snippet).
	 *
	 * @return array{url:string,messageKey:string}|null
	 */
	private static function contentUpdateTarget( string $itemId, $config, array $classIds, array $query = [] ): ?array {
		try {
			$updatePages = [
				'quotation' => 'UpdateQuotation',
				'math' => 'UpdateMath',
				'code' => 'UpdateCodeSnippet',
			];
			foreach ( $config->classIds() as $kind => $classId ) {
				if ( in_array( $classId, $classIds, true ) && isset( $updatePages[$kind] ) ) {
					return [
						'url' => SpecialPage::getTitleFor( $updatePages[$kind], $itemId )->getFullURL( $query ),
						'messageKey' => 'embeddablecontent-update-content-button',
					];
				}
			}
		} catch ( \Throwable $e ) {
			// A malformed/absent content vocabulary never breaks the
			// semantic-entity update buttons.
			return null;
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
		$parser->setFunctionHook( 'quotationsof', static function ( Parser $parser, ...$args ) use ( $services ): array {
			return QuotationsOf::onQuotationsOf(
				$services->get( 'EmbeddableContent.Config' ),
				$parser,
				$args
			);
		} );
		$parser->setFunctionHook( 'childitemsof', static function ( Parser $parser, ...$args ) use ( $services ): array {
			return \EmbeddableContent\ParserFunctions\ChildItemsOf::onChildItemsOf(
				$services->get( 'EmbeddableContent.Config' ),
				$parser,
				$args
			);
		} );
		$parser->setFunctionHook( 'osmplace', static function ( Parser $parser, ...$args ) use ( $services ): array {
			return \EmbeddableContent\ParserFunctions\OsmPlaceRow::onOsmPlaceRow(
				$services->get( 'EmbeddableContent.Config' ),
				$parser,
				$args
			);
		} );
		$parser->setFunctionHook( 'statementrow', static function ( Parser $parser, ...$args ) use ( $services ): array {
			return \EmbeddableContent\ParserFunctions\StatementRow::onStatementRow(
				$services->get( 'EmbeddableContent.Config' ),
				$parser,
				$args
			);
		} );
		$parser->setFunctionHook( 'quotationsby', static function ( Parser $parser, ...$args ) use ( $services ): array {
			return \EmbeddableContent\ParserFunctions\QuotationsBy::onQuotationsBy(
				$services->get( 'EmbeddableContent.Config' ),
				$parser,
				$args
			);
		} );
	}
}
