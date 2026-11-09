<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use EmbeddableContent\Content\SourceAccessRenderer;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Spec\AccessEntries;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\Repo\WikibaseRepo;

/**
 * `{{#source-access:}}` parser function — the "Access" infobox cell of a
 * Source: page, backed by the item's multiple access statements.
 *
 * The cell lists the visible access entries as links (`url1 | pdf | epub`),
 * all opening in a new tab. Login-only entries are omitted for anonymous
 * readers; a "Login to view additional access modes" link is appended then.
 * Logged-in readers see every entry. Because that output is viewer-dependent,
 * the parser cache is bypassed whenever the item has hidden entries.
 *
 * File links go through Special:SourceFile (preview + licence + gated
 * download); URL links go directly to the access URL.
 *
 * @license GPL-2.0-or-later
 */
final class SourceAccess {

	/** Site id of the local sitelink group (also hardcoded in Hooks.php). */
	private const SITE_ID = 'wikibase';

	/**
	 * @param EmbeddableContentConfig $config injected via the hook closure
	 * @param Parser $parser
	 * @param mixed[] $args ignored
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function onSourceAccess( EmbeddableContentConfig $config, Parser $parser, array $args ): array {
		$language = $parser->getTargetLanguage();
		$naText = wfMessage( 'embeddablecontent-source-access-na' )
			->inLanguage( $language )->text();

		$title = $parser->getTitle();
		if ( $title === null || !$title->exists() || !$title->isContentPage() ) {
			return [ 'text' => htmlspecialchars( $naText ), 'noparse' => false, 'isHTML' => true ];
		}
		$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
			->getItemIdForLink( self::SITE_ID, $title->getPrefixedText() );
		if ( $itemId === null ) {
			return [ 'text' => htmlspecialchars( $naText ), 'noparse' => false, 'isHTML' => true ];
		}
		$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		if ( !$entity instanceof Item ) {
			return [ 'text' => htmlspecialchars( $naText ), 'noparse' => false, 'isHTML' => true ];
		}

		self::registerCacheDependency( $parser, $itemId );

		$entries = AccessEntries::fromItem( $entity, $config );
		$user = $parser->getUserIdentity();
		$loggedIn = $user !== null && $user->isRegistered();

		$hasHidden = false;
		$links = [];
		foreach ( $entries as $entry ) {
			if ( empty( $entry['public'] ) ) {
				$hasHidden = true;
				if ( !$loggedIn ) {
					continue;
				}
			}
			if ( $entry['type'] === AccessEntries::TYPE_URL ) {
				$links[] = [
					'kind' => 'url',
					'href' => (string)$entry['url'],
					'label' => '',
				];
				continue;
			}
			$fileTitle = (string)( $entry['fileTitle'] ?? '' );
			if ( $fileTitle === '' ) {
				continue;
			}
			$ext = strtolower( (string)pathinfo( $fileTitle, PATHINFO_EXTENSION ) );
			$links[] = [
				'kind' => 'file',
				'href' => SpecialPage::getTitleFor( 'SourceFile' )->getFullURL( [
					'item' => $itemId->getSerialization(),
					'file' => $fileTitle,
				] ),
				'label' => $ext !== '' ? $ext : 'file',
			];
		}

		if ( $hasHidden ) {
			// The output differs between anonymous and logged-in readers.
			$parser->getOutput()->updateCacheExpiry( 0 );
		}
		$loginUrl = $hasHidden && !$loggedIn
			? SpecialPage::getTitleFor( 'UserLogin' )->getFullURL( [
				'returnto' => $title->getPrefixedText(),
			] )
			: '';
		$loginText = wfMessage( 'embeddablecontent-source-access-login' )
			->inLanguage( $language )->text();

		return [
			'text' => SourceAccessRenderer::render(
				$links,
				$naText,
				$hasHidden && !$loggedIn,
				$loginUrl,
				$loginText
			),
			'noparse' => false,
			'isHTML' => true,
		];
	}

	/**
	 * Parser-cache dependency on the item page (mirrors WikibaseCitation's
	 * CitationDependencies): ParserOutput::addTemplate() makes RefreshLinksJob
	 * re-parse this page when the item is edited.
	 */
	private static function registerCacheDependency( Parser $parser, EntityId $itemId ): void {
		$services = MediaWikiServices::getInstance();
		$title = WikibaseRepo::getEntityTitleStoreLookup( $services )->getTitleForId( $itemId );
		if ( $title === null || !$title->exists() ) {
			return;
		}
		$revId = \EmbeddableContent\Spec\LatestRevision::id( WikibaseRepo::getEntityRevisionLookup( $services ), $itemId );
		$parser->getOutput()->addTemplate( $title, $title->getArticleID(), $revId );
	}
}
