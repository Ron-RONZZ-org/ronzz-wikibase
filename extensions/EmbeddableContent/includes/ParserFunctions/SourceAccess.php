<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use DataValues\StringValue;
use EmbeddableContent\Content\SourceAccessRenderer;
use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * `{{#source-access:}}` parser function — the "Access" infobox cell of a
 * Source: page (issue follow-up, ADR docs/decisions/source-access-rendering.md).
 *
 * Resolves the CURRENT page's sitelinked item (the same site-link store the
 * Sitelink tab uses) and renders the cell from its statements:
 *
 *   * `file` statement (the copy stored on this wiki) → the file name linked
 *     to Special:SourceFile?item=<Q>&file=<File: title>;
 *   * else `access URL` statement (non-direct) → a clickable external link;
 *   * else → "N/A" (localized).
 *
 * The item page is registered as a parser-cache dependency (the
 * `{{#statements:}}` rows on the same templates already do this — belt and
 * suspenders for pages that use the function standalone), so editing the
 * item re-renders the page.
 *
 * Returns WIKITEXT (not HTML): the cell participates in the template's
 * normal parse — links render, nothing is marked noparse.
 *
 * @license GPL-2.0-or-later
 */
final class SourceAccess {

	/** Site id of the local sitelink group (also hardcoded in Hooks.php). */
	private const SITE_ID = 'wikibase';

	/**
	 * @param EmbeddableContentConfig $config injected via the hook closure
	 * @param Parser $parser
	 * @param mixed[] $args ignored (no arguments; the cell is derived from
	 *   the page's item)
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function onSourceAccess( EmbeddableContentConfig $config, Parser $parser, array $args ): array {
		$naText = wfMessage( 'embeddablecontent-source-access-na' )
			->inLanguage( $parser->getTargetLanguage() )->text();
		$text = $naText;

		$title = $parser->getTitle();
		if ( $title === null || !$title->exists() || !$title->isContentPage() ) {
			return [ 'text' => $text, 'noparse' => false, 'isHTML' => false ];
		}

		$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
			->getItemIdForLink( self::SITE_ID, $title->getPrefixedText() );
		if ( $itemId === null ) {
			return [ 'text' => $text, 'noparse' => false, 'isHTML' => false ];
		}

		$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		if ( !$entity instanceof Item ) {
			return [ 'text' => $text, 'noparse' => false, 'isHTML' => false ];
		}

		$props = $config->sourcePropertyIds();
		$fileTitles = self::fileTitles( $entity, $props['file'] ?? null );
		$accessUrls = self::stringStatementValues( $entity, $props['accessUrl'] ?? null );

		// Editing the item must re-render every page showing this cell.
		self::registerCacheDependency( $parser, $itemId );

		return [ 'text' => SourceAccessRenderer::render(
			$fileTitles, $accessUrls, $itemId->getSerialization(), $naText
		), 'noparse' => false, 'isHTML' => false ];
	}

	/**
	 * File: titles from the `file` statements. The stored value is the File:
	 * PAGE full URL (getFullURL, set at creation) — the title is extracted
	 * from the URL path and validated (NS_FILE, exists).
	 *
	 * @return string[] DBkey file titles (e.g. "File:War and Peace.pdf")
	 */
	private static function fileTitles( Item $item, ?string $propId ): array {
		if ( $propId === null ) {
			return [];
		}
		$titles = [];
		foreach ( self::stringStatementValues( $item, $propId ) as $url ) {
			$path = parse_url( $url, PHP_URL_PATH );
			if ( $path === false || $path === null || $path === '' ) {
				continue;
			}
			$title = Title::newFromText( basename( $path ) );
			if ( $title !== null && $title->inNamespace( NS_FILE ) && $title->exists() ) {
				$titles[] = $title->getDBkey();
			}
		}
		return $titles;
	}

	/**
	 * String values of a property's statements (url/external-id datatypes
	 * hold StringValues).
	 *
	 * @return string[]
	 */
	private static function stringStatementValues( Item $item, ?string $propId ): array {
		if ( $propId === null ) {
			return [];
		}
		$values = [];
		try {
			$propertyId = new PropertyId( $propId );
		} catch ( \Throwable $e ) {
			return [];
		}
		foreach ( $item->getStatements()->getByPropertyId( $propertyId ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof StringValue ) {
				$values[] = $snak->getDataValue()->getValue();
			}
		}
		return $values;
	}

	/**
	 * Parser-cache dependency on the item page (mirrors WikibaseCitation's
	 * CitationDependencies): ParserOutput::addTemplate() makes RefreshLinksJob
	 * re-parse this page when the item is edited.
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
