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
	 * set at creation) — the title is extracted from the URL (path OR query
	 * form, see fileTitleFromUrl) and validated (NS_FILE, exists).
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
				$title = self::fileTitleFromUrl( $url );
				if ( $title !== null ) {
					$titles[] = $title;
				}
			}
			if ( $titles !== [] ) {
				break;
			}
		}
		return $titles;
	}

	/**
	 * File: title from a stored `image` statement URL, or null when the URL
	 * does not reference an existing File: page.
	 *
	 * The Add* flows store `Title::getFullURL()`, whose shape follows the
	 * instance's article path — a PATH form ("…/wiki/File:X.png", production)
	 * or a QUERY form ("…/w/index.php?title=File:X.png", the dev/CI stack) —
	 * both are recognized. The file-name segment is percent-decoded (an
	 * encoded name must still match; getFullURL encodes special characters).
	 */
	private static function fileTitleFromUrl( string $url ): ?string {
		$parts = parse_url( $url );
		if ( !is_array( $parts ) ) {
			return null;
		}
		$candidate = null;
		if ( isset( $parts['query'] ) ) {
			parse_str( (string)$parts['query'], $query );
			$t = $query['title'] ?? '';
			if ( is_string( $t ) && str_starts_with( $t, 'File:' ) ) {
				$candidate = $t;
			}
		}
		if ( $candidate === null || $candidate === '' ) {
			$path = $parts['path'] ?? '';
			if ( $path !== '' ) {
				$candidate = basename( $path );
			}
		}
		if ( $candidate === null || $candidate === '' ) {
			return null;
		}
		$title = Title::newFromText( rawurldecode( $candidate ) );
		if ( $title !== null && $title->inNamespace( NS_FILE ) && $title->exists() ) {
			// getDBkey() — the FILE NAME WITHOUT the "File:" namespace
			// prefix: the rendered cell is '[[File:' . $name . ']]', and a
			// prefixed title would double the prefix into a broken-media
			// redlink (File:File:…). MW normalizes the DBkey's underscores
			// back to spaces when it renders the link.
			return $title->getDBkey();
		}
		return null;
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
