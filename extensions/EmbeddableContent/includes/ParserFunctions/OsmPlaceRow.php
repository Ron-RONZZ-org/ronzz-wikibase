<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Parser\Parser;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * `{{#osm-place:birth}}` / `{{#osm-place:death}}` parser function — the
 * "Place of birth"/"Place of death" infobox cell of a Person: page
 * (osm-places follow-up).
 *
 * Resolves the CURRENT page's sitelinked item (or, with a second argument,
 * an explicit item id — `{{#osm-place:birth|Q42}}`) and renders the OSM
 * place cell from its statements:
 *
 *   * the `place of birth (OSM)` / `place of death (OSM)` external-id
 *     statement (node|way|relation/<id>) → the human-readable display
 *     label (the parallel `place of birth (label)` / `place of death
 *     (label)` string statement, captured at creation) linked to
 *     https://www.openstreetmap.org/<id>;
 *   * when the label statement is absent (older items, ids typed by hand)
 *     → the raw id as the link text — the reader still reaches the OSM map;
 *   * when the item has no OSM place statement → '' (the cell stays empty,
 *     exactly as the old `{{#statements:…}}` row did).
 *
 * The item page is registered as a parser-cache dependency (the
 * `{{#source-access:}}` pattern), so editing the item re-renders every
 * page showing the cell.
 *
 * Returns WIKITEXT (not HTML): the cell participates in the template's
 * normal parse. The label is sanitized as wikitext link text (no [ ] |
 * { } < > — a Nominatim display name may contain anything).
 *
 * @license GPL-2.0-or-later
 */
final class OsmPlaceRow {

	/** Site id of the local sitelink group (also hardcoded in Hooks.php). */
	private const SITE_ID = 'wikibase';

	/**
	 * @param EmbeddableContentConfig $config injected via the hook closure
	 * @param Parser $parser
	 * @param mixed[] $args first arg = "birth"|"death" (required); optional
	 *   second arg = an explicit item id
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function onOsmPlaceRow( EmbeddableContentConfig $config, Parser $parser, array $args ): array {
		$which = strtolower( trim( (string)( $args[0] ?? '' ) ) );
		if ( !in_array( $which, [ 'birth', 'death' ], true ) ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}

		$itemId = self::resolveItemId( $parser, $args[1] ?? null );
		if ( $itemId === null ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}

		$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		if ( !$entity instanceof Item ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}

		$props = $config->personPropertyIds();
		$osmKey = $which === 'birth' ? 'placeOfBirthOsm' : 'placeOfDeathOsm';
		$labelKey = $which === 'birth' ? 'placeOfBirthLabel' : 'placeOfDeathLabel';
		$osmId = self::firstString( $entity, $props[$osmKey] ?? null );
		if ( $osmId === '' || preg_match( '/^(node|way|relation)\/[1-9]\d*$/', $osmId ) !== 1 ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}

		// Editing the item must re-render every page showing this cell.
		self::registerCacheDependency( $parser, $itemId );

		$label = self::firstString( $entity, $props[$labelKey] ?? null );
		if ( $label === '' ) {
			$label = $osmId;
		}
		$text = self::linkText( $osmId, $label );
		return [ 'text' => $text, 'noparse' => false, 'isHTML' => false ];
	}

	/**
	 * The cell's wikitext: `[https://www.openstreetmap.org/<id> <label>]`.
	 * The label is sanitized as external-link TEXT (strip [ ] | { } < >
	 * — a display name must never break the [url text] syntax or the
	 * template's table).
	 */
	private static function linkText( string $osmId, string $label ): string {
		$label = trim( (string)preg_replace( '/[\[\]|{}<>]/', '', $label ) );
		if ( $label === '' ) {
			$label = $osmId;
		}
		return '[https://www.openstreetmap.org/' . $osmId . ' ' . $label . ']';
	}

	/**
	 * The item: an explicit second argument when it is a valid item id,
	 * otherwise the current page's sitelinked item (template use).
	 *
	 * @param mixed $explicit
	 */
	private static function resolveItemId( Parser $parser, $explicit ): ?ItemId {
		if ( is_string( $explicit ) && trim( $explicit ) !== '' ) {
			try {
				$id = WikibaseRepo::getEntityIdParser()->parse( trim( $explicit ) );
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

	/** First string statement value of a property ('' when absent). */
	private static function firstString( Item $item, ?string $propId ): string {
		if ( $propId === null ) {
			return '';
		}
		$propertyId = new NumericPropertyId( $propId );
		foreach ( $item->getStatements()->getByPropertyId( $propertyId ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak ) {
				$value = $snak->getDataValue();
				if ( $value instanceof StringValue ) {
					return trim( $value->getValue() );
				}
			}
		}
		return '';
	}

	/**
	 * Parser-cache dependency on the item page (the `{{#source-access:}}`
	 * pattern): ParserOutput::addTemplate() makes RefreshLinksJob re-parse
	 * this page when the item is edited.
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
