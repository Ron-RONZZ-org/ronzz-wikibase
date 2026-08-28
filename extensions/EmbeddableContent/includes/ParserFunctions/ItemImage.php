<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * `{{#item-image:}}` parser function — the image/logo/portrait infobox cell
 * of the classic pages created by the Add* flows (Collective: logo,
 * Person: portrait, FOSS: logo — ADR
 * docs/decisions/infobox-image-from-statement.md).
 *
 * Renders the item's `image` statement (the File: page full URL, written at
 * creation/update/reuse) as a thumbnailed file link:
 *
 *   * `image` statement → `[[File:<title>|frameless|220px]]`
 *   * no statement → '' (the cell stays empty)
 *
 * The item is resolved from the CURRENT page's sitelink (the same site-link
 * store the Sitelink tab and `{{#source-access:}}` use) — or, with an
 * explicit id (`{{#item-image:Q42}}`), from the named item (scratch pages,
 * hand-edited pages whose page↔item link is missing).
 *
 * The image property id is the union of the instance's configured `image`
 * keys (personProperties / collectiveProperties / fossProperties /
 * imageProperties all carry one on this instance) — the function stays
 * class-agnostic without hardcoding ids, and keeps working if the sections
 * ever diverge.
 *
 * The item page is registered as a parser-cache dependency (the
 * `{{#source-access:}}` pattern), so editing the item re-renders every page
 * showing the cell.
 *
 * Returns WIKITEXT (not HTML): the cell participates in the template's
 * normal parse — the file renders, nothing is marked noparse.
 *
 * @license GPL-2.0-or-later
 */
final class ItemImage {

	/** Site id of the local sitelink group (also hardcoded in Hooks.php). */
	private const SITE_ID = 'wikibase';

	/**
	 * @param EmbeddableContentConfig $config injected via the hook closure
	 * @param Parser $parser
	 * @param mixed[] $args optional first arg = an explicit item id
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function onItemImage( EmbeddableContentConfig $config, Parser $parser, array $args ): array {
		$itemId = self::explicitItemId( $args );
		if ( $itemId === null ) {
			$title = $parser->getTitle();
			if ( $title === null || !$title->exists() || !$title->isContentPage() ) {
				return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
			}
			$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( self::SITE_ID, $title->getPrefixedText() );
		}
		if ( $itemId === null ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}

		$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		if ( !$entity instanceof Item ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}

		// Editing the item must re-render every page showing this cell.
		self::registerCacheDependency( $parser, $itemId );

		$fileTitles = self::fileTitles( $entity, $config );
		if ( $fileTitles === [] ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}
		// The FIRST image statement wins (the Add* flows write at most one
		// logo/portrait per item).
		return [ 'text' => '[[File:' . $fileTitles[0] . '|frameless|220px]]', 'noparse' => false, 'isHTML' => false ];
	}

	/**
	 * Explicit item id from the first function argument, or null when the
	 * argument is absent or not an item id.
	 *
	 * @param mixed[] $args
	 */
	private static function explicitItemId( array $args ): ?ItemId {
		$arg = trim( (string)( $args[0] ?? '' ) );
		if ( $arg === '' ) {
			return null;
		}
		try {
			$id = WikibaseRepo::getEntityIdParser()->parse( $arg );
			return $id instanceof ItemId ? $id : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * File: titles from the `image` statements of every configured image
	 * property id. The stored value is the File: PAGE full URL (getFullURL,
	 * set at creation) — the title is extracted from the URL path, percent-
	 * decoded (an encoded file name must still match), and validated
	 * (NS_FILE, exists).
	 *
	 * @return string[] DBkey file titles (e.g. "File:National Geographic
	 *                  Partners-logo.png")
	 */
	private static function fileTitles( Item $item, EmbeddableContentConfig $config ): array {
		$propIds = array_values( array_unique( array_filter( [
			$config->personPropertyIds()['image'] ?? null,
			$config->collectivePropertyIds()['image'] ?? null,
			$config->fossPropertyIds()['image'] ?? null,
			$config->imagePropertyIds()['image'] ?? null,
		] ) ) );
		$titles = [];
		foreach ( $propIds as $propId ) {
			foreach ( self::stringStatementValues( $item, $propId ) as $url ) {
				$path = parse_url( $url, PHP_URL_PATH );
				if ( $path === false || $path === null || $path === '' ) {
					continue;
				}
				$title = Title::newFromText( rawurldecode( basename( $path ) ) );
				if ( $title !== null && $title->inNamespace( NS_FILE ) && $title->exists() ) {
					// getPrefixedText() — NOT getDBkey(): the DBkey strips the
					// "File:" prefix and normalizes spaces to underscores; the
					// rendered link needs the human title.
					$titles[] = $title->getPrefixedText();
				}
			}
			if ( $titles !== [] ) {
				break;
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
	private static function stringStatementValues( Item $item, string $propId ): array {
		$values = [];
		try {
			// PropertyId is an INTERFACE in the DataModel — the concrete
			// class is NumericPropertyId (a bare `new PropertyId()` throws
			// "Cannot instantiate interface" and was silently swallowed).
			$propertyId = new NumericPropertyId( $propId );
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
	 * Parser-cache dependency on the item page (mirrors
	 * `{{#source-access:}}`'s SourceAccess::registerCacheDependency and
	 * WikibaseCitation's CitationDependencies): ParserOutput::addTemplate()
	 * makes RefreshLinksJob re-parse this page when the item is edited.
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
