<?php

declare( strict_types = 1 );

namespace EmbeddableContent;

use EmbeddableContent\ParserFunctions\ChildItemsOf;
use EmbeddableContent\ParserFunctions\ContentPayload;
use EmbeddableContent\ParserFunctions\ItemImage;
use EmbeddableContent\ParserFunctions\QuotationsOf;
use EmbeddableContent\ParserFunctions\SourceAccess;
use EmbeddableContent\Search\EntityTermSearch;
use EmbeddableContent\Spec\InternalFile;
use EmbeddableContent\Spec\InternalMention;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Parser\Parser;
use MediaWiki\Permissions\Authority;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Specials\SpecialSearch;
use Wikimedia\HtmlArmor\HtmlArmor;
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
	 * Make Wikibase's term-fallback chains include the instance's configured
	 * `fallbackLanguages` (`['fr','en','eo']`), so an item labelled only in a
	 * non-reader language still renders its label/description in the SEARCH
	 * RESULTS (`ShowSearchHitHandler` + `HtmlPageLinkRendererEndHookHandler`)
	 * and on its `Item:` page (`EntityTermsViewFactory` →
	 * `LanguageFallbackLabelDescriptionLookup`).
	 *
	 * Wikibase builds every chain from the reader language with
	 * `LanguageFallbackChainFactory` (whose chain for `en` is `[en, mul]`) and
	 * exposes no config for extra languages; the documented
	 * `addServiceManipulator()` extension point lets us rebuild the factory
	 * with a `LanguageFallback` that appends the instance languages — the
	 * same order `action=entitysearch` already uses. The GLOBAL
	 * `LanguageFallback` service is left untouched, so MediaWiki MESSAGE
	 * fallback is unaffected (the subclass only decorates the factory).
	 *
	 * @param MediaWikiServices $services
	 */
	public static function onMediaWikiServices( MediaWikiServices $services ): void {
		$config = $services->getMainConfig()->get( 'EmbeddableContentConfig' );
		$extra = is_array( $config ) && is_array( $config['fallbackLanguages'] ?? null )
			? array_values( array_map( 'strval', $config['fallbackLanguages'] ) )
			: [];
		if ( $extra === [] ) {
			return;
		}

		$manipulator = static function ( $current, MediaWikiServices $container ) use ( $extra ) {
			$fallback = new \EmbeddableContent\Spec\InstanceLanguageFallback(
				$container->getContentLanguage()->getCode(),
				$container->getLocalisationCache(),
				$container->getLanguageNameUtils(),
				$extra
			);
			// null terms-languages keeps the factory's own default (the same
			// CONTEXT_TERM set Wikibase itself passes).
			return new \Wikibase\Lib\LanguageFallbackChainFactory(
				null,
				$container->getLanguageFactory(),
				$container->getLanguageConverterFactory(),
				$fallback
			);
		};

		// The repo factory backs the Item: page + the search results; the
		// client factory backs the sitelinked content pages (same-wiki
		// install). Each is decorated only when present.
		foreach ( [ 'WikibaseRepo.LanguageFallbackChainFactory', 'WikibaseClient.LanguageFallbackChainFactory' ] as $name ) {
			if ( $services->hasService( $name ) ) {
				$services->addServiceManipulator( $name, $manipulator );
			}
		}
	}

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
		if ( $title === null || !$title->exists() ) {
			return;
		}

		// The "Add section" page action on every classic article page.
		if ( self::isClassicArticleTitle( $title ) ) {
			self::addSectionAction( $skin, $title, $links );
		}

		// Sitelink tab: content pages except the entity namespaces (Item:/
		// Property:) — the semantic entities themselves; the tab makes no
		// sense there.
		if ( !$title->isContentPage() ) {
			return;
		}
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
	 * The "Add section" page action on every classic article page. MediaWiki
	 * core only renders it for talk pages / pages carrying
	 * `__NEWSECTIONLINK__`, so an ordinary article has no way to add to the
	 * page from the page actions. Insert the action between "Edit" (or "View
	 * source") and "View history", reusing the skin's action-link styling. The
	 * target is the extension's `action=appendsection` flow
	 * (AppendSectionAction) — core's own edit surface (toolbar, Preview, Show
	 * changes, Summary, Watch; no "minor edit" checkbox) whose save APPENDS
	 * the submitted wikitext to the end of the page (no new heading).
	 * Skipped when the page already carries the core action, when the user
	 * cannot edit, or when there is no edit action to anchor to.
	 *
	 * The view key/class must NOT be `addsection`/`ca-addsection`: Vector 2022's
	 * `SkinVector22::removeAddTopicButton()` strips that item from the Views
	 * menu and replaces it with its own title-bar button pointing at core
	 * `action=edit&section=new`. A distinct `appendsection` key keeps this
	 * append action in the visible Views row (and lets the skin clone it into
	 * the low-resolution More menu).
	 *
	 * @param array<string,array<string,mixed>> &$links
	 */
	private static function addSectionAction(
		SkinTemplate $skin,
		\MediaWiki\Title\Title $title,
		array &$links
	): void {
		if ( !isset( $links['views'] ) || isset( $links['views']['addsection'] ) ) {
			return;
		}
		// MW 1.46 removed Title::quickUserCan(); the skin's Authority is the
		// current idiom (Skin extends ContextSource).
		if ( !$skin->getAuthority()->probablyCan( 'edit', $title ) ) {
			return;
		}
		$action = [
			'class' => 'ca-appendsection',
			'text' => $skin->msg( 'embeddablecontent-addsection' )->text(),
			'icon' => 'speechBubbleAdd',
			'href' => $title->getLocalURL( 'action=appendsection' ),
		];
		// Anchor on `edit` (the visible Edit action) so the new action sits
		// BETWEEN Edit and View history; fall back to `ve-edit` (VisualEditor)
		// or `viewsource` (no edit permission) when `edit` is absent.
		$anchor = isset( $links['views']['edit'] ) ? 'edit'
			: ( isset( $links['views']['ve-edit'] ) ? 've-edit'
				: ( isset( $links['views']['viewsource'] ) ? 'viewsource' : null ) );
		if ( $anchor === null ) {
			return;
		}
		$views = [];
		foreach ( $links['views'] as $key => $value ) {
			$views[$key] = $value;
			if ( $key === $anchor ) {
				$views['appendsection'] = $action;
			}
		}
		$links['views'] = $views;
	}

	/**
	 * The item↔page auto-link pair plus the label→title sync:
	 *  - Special:NewItem auto-creates a Main-namespace sitelinked classic page
	 *    for the item it just created (Flow/NewItemPageCreator) — gated to the
	 *    Special:NewItem request, so the Add* flows (which create their own
	 *    per-kind pages) and API/script item creation are untouched;
	 *  - a NEW Main-namespace classic page auto-creates (or reuses) its
	 *    sitelinked item (Flow/PageItemCreator) — the reverse direction, and
	 *    the backfill for pages that predate the item;
	 *  - a DIRECT label edit on the Item: page (or via the API/MCP) renames the
	 *    sitelinked classic page to the new label's title, leaving a redirect
	 *    (Flow/ClassicPageLabelSync) — the Update* forms already rename and
	 *    suppress this handler.
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
		// Keep the classic page title in sync with a DIRECT label edit on the
		// Item: page / via the API (the Update* forms rename themselves and
		// suppress this handler).
		\EmbeddableContent\Flow\ClassicPageLabelSync::handle(
			$wikiPage,
			$user,
			$flags
		);
		// A Source:<label>/archivedText save invalidates the parent Source:
		// page (its {{#source-archive:}} row depends on the subpage's
		// existence, which is not a template dependency).
		\EmbeddableContent\SourceArchive\SourceArchive::onPageSaved( $wikiPage );
	}

	/**
	 * Main-namespace page deletion: remove the auto-created item when the
	 * page was its only content (no statements/descriptions/aliases, and the
	 * deleted page is its only sitelink). The item created by PageItemCreator
	 * is exactly that shell; an item with authored data is left alone.
	 *
	 * @param ProperPageIdentity $page
	 * @param Authority $deleter
	 * @param string $reason
	 * @param int $pageID
	 * @param RevisionRecord $deletedRev
	 * @param ManualLogEntry $logEntry
	 * @param int $archivedRevisionCount
	 */
	public static function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	): void {
		\EmbeddableContent\Flow\OrphanItemDeleter::handle( $page, $deleter, $reason );
		\EmbeddableContent\SourceArchive\SourceArchive::onPageDeleted( $page );
	}

	/**
	 * Smart internal mention: an UNPIPED link to a classic per-kind page
	 * renders the bare entity name — `[[Person:Edwin Hubble]]` shows
	 * "Edwin Hubble", not "Person:Edwin Hubble" — and a Source: link is
	 * additionally italicised. Piped links keep the author's explicit
	 * caption; comment-rendered links (edit summaries) are untouched.
	 *
	 * @param \MediaWiki\Linker\LinkRenderer $linkRenderer
	 * @param \MediaWiki\Linker\LinkTarget $target
	 * @param string|HtmlArmor|null &$text
	 * @param array &$customAttribs
	 * @param array &$query
	 * @param string &$ret
	 * @return bool|void
	 */
	public static function onHtmlPageLinkRendererBegin(
		$linkRenderer,
		$target,
		&$text,
		&$customAttribs,
		&$query,
		&$ret
	) {
		if ( !$target instanceof \MediaWiki\Linker\LinkTarget ) {
			return;
		}
		if ( $linkRenderer instanceof \MediaWiki\Linker\LinkRenderer
			&& $linkRenderer->isForComment()
		) {
			return;
		}
		// The parser PRE-FILLS the default caption as the link text for an
		// unpiped link ("[[Person:Edwin Hubble]]" arrives as the plain string
		// "Person:Edwin Hubble", not null); other LinkRenderer callers pass
		// null. Treat either as "no explicit caption" — a pipe has chosen a
		// different caption and must be left alone. The fragment is part of
		// the parser's default text.
		$defaultCaption = $target->getPrefixedText();
		if ( $target->hasFragment() ) {
			$defaultCaption .= '#' . $target->getFragment();
		}
		$caption = $text;
		if ( $caption instanceof HtmlArmor ) {
			$caption = HtmlArmor::getHtml( $caption );
		}
		if ( $caption !== null && $caption !== $defaultCaption ) {
			// Author-year mention: the stable marker `[[Source:X|name-author]]`
			// is rendered as the CURRENT italic author-year at parse time, so a
			// later author/year correction never leaves a stale caption.
			if ( InternalMention::isSourceNamespace( $target->getNamespace() )
				&& strcasecmp( trim( (string)$caption ), 'name-author' ) === 0
			) {
				$itemId = self::sourceItemIdForTitle( $target );
				$config = MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' );
				$authorYear = $itemId !== null && $config instanceof \EmbeddableContent\EmbeddableContentConfig
					? \EmbeddableContent\Spec\AuthorYearMention::caption( $config, $itemId )
					: null;
				if ( $authorYear !== null ) {
					$text = new HtmlArmor(
						'<i>' . htmlspecialchars( $authorYear, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</i>'
					);
					$customAttribs['class'] = trim(
						( $customAttribs['class'] ?? '' ) . ' wb-author-year-mention'
					);
					$customAttribs['data-wb-source-item'] = $itemId;
					return;
				}
				// No resolvable author/year: fall back to the italic title
				// mention rather than showing the raw marker.
				$text = new HtmlArmor(
					'<i>' . htmlspecialchars( $target->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</i>'
				);
				return;
			}
			return;
		}
		$namespace = $target->getNamespace();
		if ( !InternalMention::isClassicNamespace( $namespace ) ) {
			return;
		}
		$caption = $target->getText();
		if ( $caption === '' ) {
			return;
		}
		if ( InternalMention::isSourceNamespace( $namespace ) ) {
			$text = new HtmlArmor(
				'<i>' . htmlspecialchars( $caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</i>'
			);
			return;
		}
		$text = $caption;
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
	 *    values, so the File page (opened in a destination tab by
	 *    uploadform.js; the POST itself runs in the original tab's hidden
	 *    fetch) sends the opener back to a fresh, prefilled upload form.
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
			// Author/source mode selection + the entity variants (the same
			// checkbox-decides rule the form and the server use). Empty
			// values are dropped by the File-page hand-off.
			if ( $request->getCheck( 'wpUploadAuthorIsEntity' ) ) {
				$params['wbauthorisentity'] = '1';
			}
			$authorEntity = trim( (string)$request->getVal( 'wpUploadAuthorEntity', '' ) );
			if ( $authorEntity !== '' ) {
				$params['wbauthorentity'] = $authorEntity;
			}
			if ( $request->getCheck( 'wpUploadSourceIsText' ) ) {
				$params['wbsourceistext'] = '1';
			}
			$sourceEntity = trim( (string)$request->getVal( 'wpUploadSource', '' ) );
			if ( $sourceEntity !== '' ) {
				$params['wbsource'] = $sourceEntity;
			}
			$sourceText = trim( (string)$request->getVal( 'wpUploadSourceText', '' ) );
			if ( $sourceText !== '' ) {
				$params['wbsourcetext'] = $sourceText;
			}
			$params['wblicenseinfo'] = (string)$request->getVal( 'wpUploadLicenseInfo', '' );
			// The source radio (File | Url): preserved so "upload another
			// image from same author" remembers the user's choice (a URL
			// uploader pastes another URL instead of re-picking a file). The
			// browser blob fallback's ORIGINAL selection wins over the
			// converted-internal wpSourceType (wbUploadmetaSourceType, the
			// same precedence onUploadFormSourceDescriptors uses).
			$sourceType = trim( (string)$request->getVal( 'wbUploadmetaSourceType', '' ) );
			if ( $sourceType === '' ) {
				$sourceType = trim( (string)$request->getVal( 'wpSourceType', '' ) );
			}
			if ( $sourceType !== '' ) {
				$params['wbsourcetype'] = strtolower( $sourceType );
			}
		}
		if ( $params === [] ) {
			return;
		}
		$redirect = wfAppendQuery( $redirect, $params );
	}

	/**
	 * Point the Vector 2022 / Minerva typeahead at the classic-page client.
	 *
	 * Two problems made the dropdown useless on this instance: the skin's
	 * default client calls the REST search route (/rest.php/v1/search/title),
	 * which is not routed here (it 404s as a normal page), and Wikibase's
	 * `enableEntitySearchUI` (now disabled) replaced it with an entity-only
	 * client. `ext.embeddableContent.searchclient` implements the same client
	 * interface over `action=opensearch` (page titles); entity labels stay in
	 * the Special:Search "Items" section.
	 *
	 * Wikibase's own SkinPageReadyConfig hook returns early when
	 * enableEntitySearchUI is false, so this assignment always runs.
	 */
	public static function onSkinPageReadyConfig( Context $context, array &$config ): bool {
		if ( in_array( $context->getSkin(), [ 'vector-2022', 'minerva' ], true ) ) {
			$config['searchModule'] = 'ext.embeddableContent.searchclient';
		}
		return true;
	}

	/**
	 * File: edit pages — warn that license/author/source facts live on the
	 * semantic image item, with a direct "Update basic information" link and
	 * a client-side Dismiss. The banner never blocks the wikitext edit.
	 *
	 * Hook name: EditPage::showEditForm:initial (the handler suffix is
	 * onEditPage__showEditForm_initial).
	 */
	public static function onEditPage__showEditForm_initial( $editor, $out ): void {
		if ( !$editor instanceof \MediaWiki\EditPage\EditPage
			|| !$out instanceof \MediaWiki\Output\OutputPage
		) {
			return;
		}
		$title = $editor->getTitle();
		if ( $title === null || $title->getNamespace() !== NS_FILE ) {
			return;
		}
		$updateUrl = \MediaWiki\SpecialPage\SpecialPage::getTitleFor(
			'UpdateFile',
			$title->getPrefixedText()
		)->getFullURL();
		$html = '<div id="wb-file-edit-banner" class="wb-file-edit-banner">'
			. \MediaWiki\Html\Html::warningBox(
				'<p>' . wfMessage( 'embeddablecontent-file-edit-banner' )->parse() . '</p>'
				. '<p><a class="mw-ui-button mw-ui-progressive" href="'
				. htmlspecialchars( $updateUrl ) . '">'
				. wfMessage( 'embeddablecontent-file-edit-banner-update' )->escaped() . '</a> '
				. '<button type="button" class="mw-ui-button wb-file-edit-banner-dismiss">'
				. wfMessage( 'embeddablecontent-file-edit-banner-dismiss' )->escaped() . '</button></p>'
			)
			. '</div>';
		$out->addHTML( $html );
		$out->addModules( 'ext.embeddableContent.fileeditbanner' );
	}

	public static function onBeforePageDisplay( OutputPage $out, $skin ): void {
		$title = $out->getTitle();
		if ( $title === null ) {
			return;
		}

		// Source:<label>/archivedText: the automatic-archive disclaimer
		// banner. OutputPage-level (not parser output) so it is not cached
		// and can be dismissed for the current view only — a refresh brings
		// it back. It prints with the page (browser Save-as-PDF / Ctrl-P).
		if ( $out->isArticle()
			&& \EmbeddableContent\SourceArchive\SourceArchiveBanner::isArchivePage( $title )
		) {
			$out->prependHTML(
				\EmbeddableContent\SourceArchive\SourceArchiveBanner::html(
					$title,
					$out,
					MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' )
				)
			);
			$out->addModules( 'ext.embeddableContent.sourcearchive' );
		}

		// Internal-use media: logged-in readers carry a short-lived signed
		// cookie that nginx verifies for /images/ requests (secure_link),
		// so the media gate costs no PHP request. Anonymous users get no
		// cookie; the File: page and Special:SourceFile show a login prompt.
		InternalFile::setMediaCookie( $out->getRequest()->response(), $out->getUser() );

		// Core edit page: a stale CSRF edit token forces a PREVIEW
		// (session_fail_preview) instead of saving. When the POST is
		// same-origin, mark the response so resources/editsessionretry.js can
		// resubmit it ONCE with the fresh token the re-rendered form carries.
		// A cross-site POST (or a missing Origin/Referer) keeps core's safe
		// preview; wbTokenRetry stops a retry loop.
		self::wireEditSessionRetry( $out );

		// Display titles: `User:Rongzhou/Pretty good Linux` renders as
		// "Pretty good Linux by @Rongzhou" (the author link is added by
		// resources/userpagetitle.js; the server text is the no-JS fallback
		// and the correct <title>), and a content page in a non-main
		// namespace (`Source:Beloved`) renders as "Beloved in Source".
		// Both rules share one article-view guard + one setPageTitle path.
		self::applyDisplayTitle( $out, $title );

		// Printable-version enhancements on every existing article page
		// (namespace/subpage-stripped title + centered authors + the
		// cover-page popup); Special:, Item:/Property: and File: pages are
		// excluded (File: has its own copy toolbar).
		self::wirePrintModule( $out, $title );

		// Special:Upload — the semantic license combobox needs the entity
		// autocomplete (native formatting, same as the Add* pages) and the
		// URL validate button + 429 blob fallback. The wiring span is
		// rendered by UploadHooks::onUploadFormSourceDescriptors; these
		// modules make it functional. Without them the combobox is a plain
		// OOUI widget and the validate button never renders.
		if ( $title->isSpecial( 'Upload' ) ) {
			// The fetch submission (resources/uploadform.js) reads
			// this marker from the response document: a stale CSRF token is
			// refreshed and the form + selected file are kept, instead of
			// leaving the user on a "loss of session data" page.
			$request = \MediaWiki\Context\RequestContext::getMain()->getRequest();
			$user = \MediaWiki\Context\RequestContext::getMain()->getUser();
			if ( $request->wasPosted() && $request->getCheck( 'wpUpload' )
				&& !$user->matchEditToken( $request->getVal( 'wpEditToken' ) )
			) {
				$out->addBodyClasses( 'wb-upload-token-failed' );
			}
			$out->addModules( 'ext.embeddableContent.entitysuggest' );
			$out->addModules( 'ext.embeddableContent.uploadmeta' );
			$out->addModules( 'ext.embeddableContent.entityconfirm' );
			// Source-field gating, the empty author/license warning and the
			// same-origin fetch upload submission (2026-10 batch).
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
			// Internal-use file: anonymous readers keep the description and
			// metadata ("file info") but not the media controls — the core
			// file block is hidden by the style module, and the notice is
			// added by onImageOpenShowImageInlineBefore. Logged-in readers
			// get the normal page (the media cookie set above passes the
			// nginx gate).
			if ( InternalFile::isInternal( $title ) ) {
				$out->addBodyClasses( 'wb-internal-file' );
				if ( $out->getUser()->isAnon() ) {
					$out->addBodyClasses( 'wb-internal-file-anon' );
					$out->addModuleStyles( [
						'mediawiki.codex.messagebox.styles',
						'ext.embeddableContent.internalfile',
					] );
					return;
				}
			}
			$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title );
			if ( $file !== false ) {
				$out->addJsConfigVars( 'wbFileName', $title->getText() );
				$out->addJsConfigVars( 'wbFileUrl', $file->getFullUrl() );
				$out->addJsConfigVars( 'wbFileMime', (string)$file->getMimeType() );
				$out->addModules( 'ext.embeddableContent.filepage' );
				// The File page's semantic facts + new-version upload:
				// Special:UpdateFile. Shown even for a legacy file without an
				// item (the page then offers to create one).
				$out->addJsConfigVars(
					'wbUpdateBasicInfoUrl',
					\MediaWiki\SpecialPage\SpecialPage::getTitleFor(
						'UpdateFile',
						$title->getPrefixedText()
					)->getFullURL()
				);
				$out->addJsConfigVars( 'wbUpdateBasicInfoLabel', 'embeddablecontent-update-metadata' );
				$out->addModules( 'ext.embeddableContent.updatebutton' );
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
			self::wireSectionControls( $out, $title );
			return;
		}

		// Every other classic article page (Main, Help, Cheatsheet,
		// HowItWorks, Forum, RonzzIT, …) — the per-section controls, plus the
		// page-level "Copy internal mention"/"Copy citation" toolbar on
		// content pages. Entity pages (Item:/Property:), Special pages,
		// File: pages, the technical namespaces and talk pages are excluded;
		// the per-kind classic namespaces are handled above.
		if ( self::isClassicArticleTitle( $title ) ) {
			self::wireSectionControls( $out, $title );
			if ( $title->isContentPage() ) {
				// The "Copy citation" action cites the PAGE itself (title +
				// canonical URL + last-revision date) — NOT a sitelinked item.
				$out->addJsConfigVars( 'wbCitePage', $title->getPrefixedText() );
				$out->addModules( 'ext.embeddableContent.contentpagetoolbar' );
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

		self::wireItemToolbar( $out, $entityId->getSerialization() );

		// Entity-page toolbar parity with the classic per-kind pages: an item
		// whose `wikibase` sitelink points at a classic per-kind page
		// (Source:/Person:/Collective:/FOSS:/Software:) renders the SAME
		// "Copy internal mention" button. The copied link TARGETS that
		// classic page (the reader-facing page), never Item:Q… — which is
		// why the item→page lookup lives here instead of reusing wgTitle.
		// An item with no classic page (a quotation, an API-created item)
		// renders nothing: there is no page to mention.
		$classicTitle = self::classicPageTitleForItem( $entityId->getSerialization() );
		if ( $classicTitle !== null ) {
			self::wireMentionButton( $out, $classicTitle, $entityId->getSerialization() );
		}

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
	 * Surface Wikibase items in Special:Search.
	 *
	 * Core SearchMySQL indexes page TEXT: Item: pages carry no page text, so
	 * an item label can never appear in the normal results — searching
	 * "Ring" misses "2.2: Rings" even though the item's label contains it.
	 * The term store has the labels, but wbsearchentities is prefix-only and
	 * is only used by the combobox API. This hook appends a compact "Items"
	 * section backed by the same CONTAINS search (Search\EntityTermSearch)
	 * the comboboxes use.
	 *
	 * Scope: the default/all profiles, the normal `advanced` landing profile
	 * (whose namespace set includes Item on this instance), and an explicit
	 * Item: namespace selection. A search restricted to another namespace
	 * keeps core's results only.
	 */
	public static function onSpecialSearchResultsAppend(
		SpecialSearch $specialSearch,
		OutputPage $output,
		string $term
	): void {
		$term = trim( $term );
		if ( $term === '' || $specialSearch->getPrefix() !== '' ) {
			return;
		}
		$namespace = WikibaseRepo::getEntityNamespaceLookup()
			->getEntityNamespace( Item::ENTITY_TYPE );
		if ( $namespace === false ) {
			return;
		}
		// The normal site search lands on `profile=advanced` (the request
		// carries `fulltext=1` and no explicit profile), whose resolved
		// namespace set includes Item: on this instance. `default`/`all`
		// always include it; an explicit namespace selection includes it
		// only when the user kept the Item checkbox.
		$profile = (string)$specialSearch->getProfile();
		$namespaces = $specialSearch->getNamespaces();
		$explicitNamespace = false;
		foreach ( $specialSearch->getRequest()->getValueNames() as $name ) {
			if ( preg_match( '/^ns\d+$/', $name ) === 1 ) {
				$explicitNamespace = true;
				break;
			}
		}
		if ( !in_array( $namespace, $namespaces, true )
			&& $profile !== 'default' && $profile !== 'all' && $profile !== ''
			&& !( $profile === 'advanced' && !$explicitNamespace )
		) {
			return;
		}

		$search = MediaWikiServices::getInstance()
			->getService( 'EmbeddableContent.EntityTermSearch' );
		$entries = $search->search(
			$term,
			$output->getLanguage()->getCode(),
			10
		);
		if ( $entries === [] ) {
			return;
		}

		$linkRenderer = $specialSearch->getLinkRenderer();
		$items = '';
		foreach ( $entries as $entry ) {
			$id = (string)( $entry['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$label = (string)( $entry['label'] ?? $id );
			$link = $linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'EntityPage', $id ),
				$label
			);
			$description = (string)( $entry['description'] ?? '' );
			$items .= Html::rawElement(
				'li',
				[],
				Html::rawElement( 'div', [ 'class' => 'mw-search-result-heading' ], $link )
				. ( $description !== ''
					? Html::element( 'div', [ 'class' => 'searchresult' ], $description )
					: '' )
			);
		}
		if ( $items === '' ) {
			return;
		}

		$output->addModuleStyles( 'ext.embeddableContent.search' );
		$output->addHTML(
			Html::rawElement(
				'div',
				[ 'class' => 'wb-search-items' ],
				Html::element(
					'h2',
					[ 'class' => 'wb-search-items-heading' ],
					$specialSearch->msg( 'embeddablecontent-search-items' )->text()
				)
				. Html::rawElement( 'ul', [], $items )
			)
		);
	}

	/**
	 * Render the article H1 with the house display-title rules:
	 *
	 *  - `User:Rongzhou/Pretty good Linux` → "Pretty good Linux by
	 *    @Rongzhou": the root segment is the author (the username), the rest
	 *    is the page title. The author is a real link (added client-side —
	 *    OutputPage's page-title sanitizer allows only formatting tags, not
	 *    <a>); the server-side setPageTitle() gives no-JS readers the same
	 *    text and fixes the browser <title>.
	 *  - a content page in any other non-main namespace (`Source:Beloved`)
	 *    → "Beloved in Source" (Spec\NamespacePageTitle).
	 *
	 * Root user pages, Main-namespace pages and non-view requests
	 * (edit/history/raw) keep the real page title. The single isArticle()
	 * guard here is the shared gate for both rules.
	 */
	private static function applyDisplayTitle(
		OutputPage $out,
		\MediaWiki\Title\Title $title
	): void {
		// The H1 is only the article view; edit/history/diff must keep the
		// real page title.
		if ( !$out->isArticle() ) {
			return;
		}
		$namespace = $title->getNamespace();
		if ( $namespace === NS_USER && $title->isSubpage() ) {
			self::applyUserPageTitle( $out, $title );
			return;
		}
		if ( $namespace === NS_MAIN || $namespace === NS_USER || !$title->isContentPage() ) {
			return;
		}
		$in = wfMessage( 'embeddablecontent-namespacepagetitle-in' )
			->inLanguage( $title->getPageLanguage() )
			->text();
		$display = \EmbeddableContent\Spec\NamespacePageTitle::html(
			$title->getText(),
			(string)$title->getNsText(),
			$in
		);
		if ( $display !== null ) {
			$out->setPageTitle( $display );
			// Progressive enhancement: the page-title sanitizer keeps the
			// <span>s but strips <a>, so the namespace name becomes an
			// index link client-side (the userpagetitle.js pattern). The
			// numeric namespace id keeps the URL stable across localizations.
			$out->addJsConfigVars( 'wbNamespacePageTitle', [
				'url' => SpecialPage::getTitleFor(
					'EntityIndex',
					(string)$namespace
				)->getLocalURL(),
			] );
			$out->addModules( 'ext.embeddableContent.namespacepagetitle' );
		}
	}

	/**
	 * Userspace subpage branch of applyDisplayTitle(): "<subpage> by @<user>"
	 * plus the client-side author link wiring.
	 */
	private static function applyUserPageTitle(
		OutputPage $out,
		\MediaWiki\Title\Title $title
	): void {
		$parts = \EmbeddableContent\Spec\UserPageTitle::split( $title->getText() );
		if ( $parts === null ) {
			return;
		}
		$userTitle = \MediaWiki\Title\Title::newFromText( 'User:' . $parts['user'] );
		if ( $userTitle === null ) {
			return;
		}
		$by = wfMessage( 'embeddablecontent-userpagetitle-by' )
			->inLanguage( $title->getPageLanguage() )
			->text();
		$out->setPageTitle( $parts['title'] . ' ' . $by . ' @' . $parts['user'] );
		$out->addJsConfigVars( 'wbUserPageTitle', [
			'title' => $parts['title'],
			'by' => $by,
			'user' => $parts['user'],
			'url' => $userTitle->getLocalURL(),
			'exists' => $userTitle->isKnown(),
		] );
		$out->addModules( 'ext.embeddableContent.userpagetitle' );
	}

	/**
	 * Wire the printable-version module on an existing article page: the
	 * namespace- and subpage-stripped title (`Title::getSubpageText()`, the
	 * `wbPrintTitle` JS config) plus the "Print this page" toolbar button and
	 * the core sidebar "Printable version" link interception. Special pages,
	 * Wikibase entity pages and File: pages are skipped (the File: page
	 * already has its own copy toolbar).
	 *
	 * `wbPrintToolbar` tells the button which page-toolbar surface to join:
	 * the classic per-kind pages carry the shared `.wb-embed-toolbar` row
	 * (block, below the title), ordinary content pages the inline
	 * `.wb-content-page-toolbar`; every other article page gets the block row.
	 */
	private static function wirePrintModule( OutputPage $out, \MediaWiki\Title\Title $title ): void {
		if ( !$title->exists() || $title->isSpecialPage()
			|| $title->getNamespace() === NS_FILE
			|| self::isEntityNamespace( $title->getNamespace() )
		) {
			return;
		}
		// An archive page has no contributors of its own to credit and no
		// meaningful subpage title ("archivedText"): its print cover names the
		// SOURCE work and its archive provenance instead (print.js).
		$printTitle = $title->getSubpageText();
		$archive = null;
		if ( \EmbeddableContent\SourceArchive\SourceArchiveBanner::isArchivePage( $title ) ) {
			$archive = \EmbeddableContent\SourceArchive\SourceArchiveBanner::coverData(
				$title,
				$out,
				MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' )
			);
		}
		if ( $archive !== null ) {
			$printTitle = $archive['filename'];
			$out->addJsConfigVars( 'wbPrintArchive', $archive );
		}
		$out->addJsConfigVars( 'wbPrintTitle', $printTitle );
		// The classic per-kind namespaces (Source:/Person:/…) ARE content
		// namespaces but carry the block `.wb-embed-toolbar` row, not the
		// inline content-page toolbar — exclude them from the 'content'
		// surface.
		$contentPage = $title->isContentPage()
			&& !self::isClassicEntityNamespace( $title->getNamespace() );
		$out->addJsConfigVars( 'wbPrintToolbar', $contentPage ? 'content' : 'embed' );
		$out->addModules( 'ext.embeddableContent.print' );
	}

	/**
	 * Adds the automatic section anchors to the parsed HTML: a heading whose
	 * generated id contains math or an illegal fragment character gets an
	 * empty `<span id="…">` derived by Spec\SectionAnchor, so the per-section
	 * "Copy internal mention" / "Copy URL" controls can link to it without a
	 * hand-written `{{anchor}}` (an explicit anchor still wins).
	 *
	 * `ParserAfterTidy` is the same point CodeBlockSpaces uses: the heading
	 * markup and MediaWiki's legacy escaped span already exist, and the parser
	 * cache stores the transformed HTML. Changing this pass requires a parser
	 * cache purge on deploy.
	 */
	public static function onParserAfterTidy( Parser $parser, string &$text ): void {
		$text = \EmbeddableContent\Spec\HeadingAnchors::inject( $text );
		// Author-year mentions carry the source item id on the rendered link;
		// register each as a parser-cache dependency so a later author/year
		// correction re-renders every page that mentions the source.
		if ( preg_match_all( '/data-wb-source-item="(Q[1-9]\d*)"/', $text, $matches ) > 0 ) {
			$services = MediaWikiServices::getInstance();
			foreach ( array_unique( $matches[1] ) as $qid ) {
				try {
					$id = new \Wikibase\DataModel\Entity\ItemId( $qid );
					$title = WikibaseRepo::getEntityTitleStoreLookup( $services )->getTitleForId( $id );
					$revId = \EmbeddableContent\Spec\LatestRevision::id(
						WikibaseRepo::getEntityRevisionLookup( $services ),
						$id
					);
				} catch ( \Throwable $e ) {
					continue;
				}
				if ( $title !== null && $title->exists() ) {
					$parser->getOutput()->addTemplate( $title, $title->getArticleID(), $revId );
				}
			}
		}
	}

	/**
	 * Mark a same-origin edit POST whose CSRF token was stale, and load the
	 * module that resubmits it once with the (already refreshed) token.
	 *
	 * Core's EditPage forces a preview on a token mismatch — the deliberate
	 * defense against off-site form posts. We only bypass that one extra step
	 * when the original POST came from this wiki (Origin/Referer host), the
	 * user is still logged in, and this is not the automatic retry itself.
	 * Every other case keeps the preview and the user's edit text.
	 */
	private static function wireEditSessionRetry( OutputPage $out ): void {
		$request = \MediaWiki\Context\RequestContext::getMain()->getRequest();
		if ( !$request->wasPosted() || !$request->getCheck( 'wpTextbox1' ) ) {
			return;
		}
		// Preview / diff are explicit requests, not a failed save.
		if ( $request->getCheck( 'wpPreview' ) || $request->getCheck( 'wpDiff' ) ) {
			return;
		}
		// The one automatic retry already happened: never loop.
		if ( $request->getCheck( 'wbTokenRetry' ) ) {
			return;
		}
		$user = \MediaWiki\Context\RequestContext::getMain()->getUser();
		if ( $user->isAnon() ) {
			return;
		}
		if ( $user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			return;
		}
		$origin = $request->getHeader( 'Origin' );
		if ( $origin === null || $origin === '' ) {
			$origin = $request->getHeader( 'Referer' );
		}
		if ( $origin === null || $origin === ''
			|| !\EmbeddableContent\Spec\SameOrigin::matches( $origin, $request->getFullRequestURL() )
		) {
			return;
		}
		$out->addBodyClasses( 'wb-edit-token-refreshed' );
		$out->addModules( 'ext.embeddableContent.editsessionretry' );
	}

	/**
	 * The per-section controls (Copy internal mention / Copy URL / Add section
	 * below / Delete section), loaded next to each heading's `[edit]` link on
	 * every classic article page. `wbReferenceSnippet` is the server-resolved
	 * `[[Page name]]` the section "Copy internal mention" control reuses
	 * (Spec\MentionSnippet, the same snippet the page-level button copies).
	 * A Main-namespace page in Category:Proper name keeps its capital initial
	 * (the category is the explicit opt-out; every other Main title is
	 * sentence-cased and resolves identically).
	 */
	private static function wireSectionControls(
		OutputPage $out,
		\MediaWiki\Title\Title $title
	): void {
		$prefixed = $title->getPrefixedText();
		$isMain = $title->getNamespace() === NS_MAIN;
		$out->addJsConfigVars(
			'wbReferenceSnippet',
			\EmbeddableContent\Spec\MentionSnippet::fromPrefixedTitle(
				$prefixed,
				$isMain,
				in_array(
					\EmbeddableContent\Spec\MentionSnippet::PROPER_NAME_CATEGORY,
					$out->getCategories(),
					true
				)
			)
		);
		// The right-click "Capitalise first letter" variant of the same link.
		$out->addJsConfigVars(
			'wbReferenceSnippetCapitalized',
			\EmbeddableContent\Spec\MentionSnippet::capitalizedFromPrefixedTitle( $prefixed, $isMain )
		);
		$out->addModules( 'ext.embeddableContent.sectionactions' );
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
	 * The classic per-kind page sitelinked to an item, or null when the item
	 * has no `wikibase` sitelink, the sitelink points outside the classic
	 * per-kind namespaces (a Main-namespace page, …), or the page does not
	 * exist.
	 *
	 * Used by the Item: page's "Copy internal mention" button: the mention
	 * must copy a link to the human-readable classic page
	 * (`[[Person:Carl David Anderson|Carl David Anderson]]`), never to the
	 * data page (`[[Item:Q2512]]`).
	 */
	private static function classicPageTitleForItem( string $itemId ): ?\MediaWiki\Title\Title {
		$title = \EmbeddableContent\Spec\ItemPageTitle::forItem( $itemId );
		if ( $title === null || !self::isClassicEntityNamespace( $title->getNamespace() ) ) {
			return null;
		}
		return $title->exists() ? $title : null;
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
	 * Whether the title is a "classic wiki page" — the scope of the per-section
	 * controls and the "Add section" action: any existing article page, EXCEPT
	 * the Special: namespace, the Wikibase entity namespaces (Item:/Property:),
	 * File: pages (they carry their own copy toolbar), the technical namespaces
	 * (Category:/Template:/MediaWiki:/Module:) and every talk page.
	 */
	private static function isClassicArticleTitle( \MediaWiki\Title\Title $title ): bool {
		if ( !$title->exists() || $title->isSpecialPage() || $title->isTalkPage() ) {
			return false;
		}
		$namespace = $title->getNamespace();
		if ( $namespace === NS_FILE || $namespace === NS_CATEGORY
			|| $namespace === NS_TEMPLATE || $namespace === NS_MEDIAWIKI
			|| ( defined( 'NS_MODULE' ) && $namespace === NS_MODULE )
		) {
			return false;
		}
		return !self::isEntityNamespace( $namespace );
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
	 * The "Copy internal mention" button on the classic per-kind pages AND
	 * on the Item: page of an item sitelinked to one (toolbar parity — the
	 * Item page passes the CLASSIC title, so the mention links to the
	 * readable page, not to Item:Q…). It copies a plain internal link,
	 * `[[<page>]]`; the rendered caption is the smart mention (namespace
	 * stripped, Source italicised) via onHtmlPageLinkRendererBegin.
	 * Rendered in the shared toolbar; resources/mention.js places it to the
	 * right of the "Copy internal citation" button on source pages.
	 */
	private static function wireMentionButton(
		OutputPage $out,
		\MediaWiki\Title\Title $title,
		string $itemId
	): void {
		$prefixed = $title->getPrefixedText();
		$out->addJsConfigVars( 'wbMentionLink', $prefixed );
		// The right-click "Capitalise first letter" variant (page name after
		// the namespace prefix, never the prefix itself).
		$out->addJsConfigVars(
			'wbMentionLinkCapitalized',
			\EmbeddableContent\Spec\MentionSnippet::capitalizePrefixedTitle(
				$prefixed,
				$title->getNamespace() === NS_MAIN
			)
		);
		// Author-year variant: `[[Source:X|name-author]]` renders the CURRENT
		// authors + year at parse time. Only offer it when the item actually
		// has both.
		$config = MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' );
		$out->addJsConfigVars(
			'wbMentionAuthorYearAvailable',
			$config instanceof \EmbeddableContent\EmbeddableContentConfig
				&& \EmbeddableContent\Spec\AuthorYearMention::caption( $config, $itemId ) !== null
		);
		$out->addModules( 'ext.embeddableContent.mention' );
	}

	/** The item id sitelinked to a Source: page, or null. */
	private static function sourceItemIdForTitle( $target ): ?string {
		try {
			$id = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( 'wikibase', $target->getPrefixedText() );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $id?->getSerialization();
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
		// The "Archived text" row on a Source: page: emitted only when the
		// Source:<label>/archivedText subpage exists.
		$parser->setFunctionHook( 'sourcearchive', static function ( Parser $parser ): array {
			return \EmbeddableContent\ParserFunctions\SourceArchiveRow::onSourceArchive( $parser );
		} );
		$parser->setFunctionHook( 'itemimage', static function ( Parser $parser, ...$args ) use ( $services ): array {
			return ItemImage::onItemImage(
				$services->get( 'EmbeddableContent.Config' ),
				$parser,
				$args
			);
		} );
		$parser->setFunctionHook( 'imagefacts', static function ( Parser $parser, ...$args ) use ( $services ): array {
			return \EmbeddableContent\ParserFunctions\ImageFacts::onImageFacts(
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

	/**
	 * File: page notice for an internal-use file viewed anonymously. The
	 * core file block itself is hidden by the internalfile style module; this
	 * notice states why and offers the login link. Logged-in readers need no
	 * notice — the page renders normally.
	 *
	 * @param \MediaWiki\Page\ImagePage $imagePage
	 * @param OutputPage $out
	 */
	public static function onImageOpenShowImageInlineBefore( $imagePage, OutputPage $out ): void {
		$title = $imagePage->getTitle();
		if ( $title === null || !InternalFile::isInternal( $title ) || !$out->getUser()->isAnon() ) {
			return;
		}
		$out->addModuleStyles( [
			'mediawiki.codex.messagebox.styles',
			'ext.embeddableContent.internalfile',
		] );
		$message = $out->msg( 'embeddablecontent-internal-file-loginrequired' )->text();
		$login = Html::element(
			'a',
			[ 'href' => InternalFile::loginUrl( $title ) ],
			$out->msg( 'login' )->text()
		);
		$out->addHTML( Html::warningBox( htmlspecialchars( $message ) . ' ' . $login ) );
	}

	/**
	 * UserLogoutComplete: drop the signed media cookie. Without this a stale
	 * cookie would keep granting internal-media access after logout until
	 * its one-hour expiry.
	 *
	 * @param \MediaWiki\User\User $user
	 * @param string &$injectHtml
	 * @param string $oldName
	 */
	public static function onUserLogoutComplete( $user, &$injectHtml, $oldName ): void {
		InternalFile::clearMediaCookie( \MediaWiki\Context\RequestContext::getMain()->getRequest()->response() );
	}

	/**
	 * FileDeleteComplete: drop the media marker with the file (the File:
	 * page and its category are gone). Old-version deletions keep it.
	 *
	 * @param mixed $file
	 * @param mixed $oldimage
	 * @param mixed $article
	 * @param mixed $user
	 * @param string $reason
	 */
	public static function onFileDeleteComplete( $file, $oldimage, $article, $user, $reason ): void {
		if ( $oldimage !== null ) {
			return;
		}
		try {
			InternalFile::removeMarkerForFile( $file );
		} catch ( \Throwable $e ) {
			wfLogWarning( 'EmbeddableContent: could not remove the internal-file marker: ' . $e->getMessage() );
		}
	}

	/**
	 * FileUndeleteComplete: restore the media marker from the restored
	 * File: page's category.
	 *
	 * @param \MediaWiki\Title\Title $title
	 * @param array $fileVersions
	 * @param mixed $user
	 * @param string $reason
	 */
	public static function onFileUndeleteComplete( $title, $fileVersions, $user, $reason ): void {
		try {
			InternalFile::syncOne( $title );
		} catch ( \Throwable $e ) {
			wfLogWarning( 'EmbeddableContent: could not restore the internal-file marker: ' . $e->getMessage() );
		}
	}
}
