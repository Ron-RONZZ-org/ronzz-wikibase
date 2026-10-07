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
 * `{{#statement-row:Label|property}}` parser function — the conditional
 * infobox row for the classic per-kind pages (Person:/Source:/FOSS:/
 * Software:). Emits a COMPLETE wikitext table row when the page's
 * sitelinked item carries data for the named property, and NOTHING
 * otherwise, so an infobox never shows a labelled empty cell.
 *
 * ParserFunctions' `#if` is not installed on the instance, so the
 * empty-row hiding lives in the function (the `{{#quotations-of:}}` /
 * `{{#child-items-of:}}` pattern). The row VALUE is rendered by Wikibase's
 * own `{{#statements:}}` (nested in the returned wikitext and expanded by
 * the parser), so the data formatting/linking is unchanged — this function
 * only decides whether the row exists.
 *
 * Usage:
 *
 *   {{#statement-row:Date of birth|date of birth}}
 *   {{#statement-row:Place of birth|osm-birth}}
 *   {{#statement-row:Place of birth|osm-birth|Q42}}   (explicit item)
 *
 * The second argument is either a property label (resolved through the
 * WikibaseClient property-label resolver, the same one `{{#statements:}}`
 * uses) or a property id (`P…`); or one of the OSM renderer keywords
 * `osm-birth` / `osm-death` / `osm-jurisdiction`, which render the
 * `{{#osm-place:}}` cell (its backing config property is what gets
 * checked).
 *
 * The item page is registered as a parser-cache dependency (the
 * `{{#source-access:}}` pattern), so editing the item re-renders the page.
 *
 * Returns WIKITEXT (not HTML): the row participates in the template's
 * normal parse.
 *
 * @license GPL-2.0-or-later
 */
final class StatementRow {

	/** Site id of the local sitelink group (also hardcoded in Hooks.php). */
	private const SITE_ID = 'wikibase';

	/**
	 * OSM-place row renderer keywords: `{{#osm-place:}}` selector => the
	 * config property that proves the row has data.
	 */
	private const OSM_RENDERERS = [
		'osm-birth' => 'birth',
		'osm-death' => 'death',
		'osm-jurisdiction' => 'jurisdiction',
	];

	/**
	 * @param EmbeddableContentConfig $config injected via the hook closure
	 * @param Parser $parser
	 * @param mixed[] $args [0] row label, [1] property label/id or an OSM
	 *   renderer keyword, [2] optional explicit item id
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function onStatementRow( EmbeddableContentConfig $config, Parser $parser, array $args ): array {
		$label = trim( (string)( $args[0] ?? '' ) );
		$spec = trim( (string)( $args[1] ?? '' ) );
		$explicit = isset( $args[2] ) ? trim( (string)$args[2] ) : '';
		if ( $label === '' || $spec === '' ) {
			return self::emptyResult();
		}

		$itemId = self::resolveItemId( $parser, $explicit );
		if ( $itemId === null ) {
			return self::emptyResult();
		}
		$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		if ( !$entity instanceof Item ) {
			return self::emptyResult();
		}

		// OSM place rows: checked against the config property behind
		// {{#osm-place:…}}, rendered by that same function.
		// The nested value renderer carries the explicit entity through
		// {{#statements:…|from=Q…}} (Wikibase's named entity argument), so an
		// explicit-id call works outside the sitelinked page context too.
		$from = $explicit !== '' ? '|from=' . $explicit : '';

		if ( isset( self::OSM_RENDERERS[$spec] ) ) {
			$which = self::OSM_RENDERERS[$spec];
			if ( !self::osmHasData( $config, $entity, $which ) ) {
				return self::emptyResult();
			}
			self::registerCacheDependency( $parser, $itemId );
			$value = '{{#osm-place:' . $which
				. ( $explicit !== '' ? '|' . $explicit : '' ) . '}}';
			return self::row( $label, $value );
		}

		if ( preg_match( '/^P[1-9]\d*$/i', $spec ) === 1 ) {
			$propertyId = strtoupper( $spec );
		} else {
			try {
				$propertyId = self::resolvePropertyLabel( $spec );
			} catch ( \Throwable $e ) {
				// Resolver failure: fail OPEN — never hide data we cannot
				// verify. The nested {{#statements:label}} renders normally.
				return self::row( $label, '{{#statements:' . $spec . $from . '}}' );
			}
			if ( $propertyId === null ) {
				// Unknown property label: the row would render an empty
				// cell, so hide it.
				return self::emptyResult();
			}
		}

		try {
			$property = new NumericPropertyId( $propertyId );
		} catch ( \Throwable $e ) {
			return self::emptyResult();
		}
		if ( $entity->getStatements()->getByPropertyId( $property )->isEmpty() ) {
			return self::emptyResult();
		}

		self::registerCacheDependency( $parser, $itemId );
		return self::row( $label, '{{#statements:' . $propertyId . $from . '}}' );
	}

	/**
	 * A complete table row: `\n|-\n| Label || value`. Pure (unit-tested) —
	 * the surrounding template table continues after the function's
	 * closing newline.
	 */
	public static function buildRow( string $label, string $value ): string {
		return "\n|-\n| " . $label . ' || ' . $value;
	}

	/** @return array{text:string,noparse:bool,isHTML:bool} */
	private static function row( string $label, string $value ): array {
		return [
			'text' => self::buildRow( $label, $value ),
			'noparse' => false,
			'isHTML' => false,
		];
	}

	/** @return array{text:string,noparse:bool,isHTML:bool} */
	private static function emptyResult(): array {
		return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
	}

	/**
	 * The property id for a property label, or null when the content
	 * language has no property with that label. Throws when the
	 * WikibaseClient resolver is unavailable (the caller fails open).
	 */
	private static function resolvePropertyLabel( string $label ): ?string {
		if ( !class_exists( \Wikibase\Client\WikibaseClient::class ) ) {
			throw new \RuntimeException( 'WikibaseClient is not available for property label resolution' );
		}
		$resolver = \Wikibase\Client\WikibaseClient::getPropertyLabelResolver();
		$ids = $resolver->getPropertyIdsForLabels( [ $label ] );
		if ( !isset( $ids[$label] ) ) {
			return null;
		}
		return $ids[$label]->getSerialization();
	}

	/**
	 * Whether the item carries data for an OSM-place row: the OSM external-id
	 * statement (birth/death/jurisdiction), or — for a legal text — the
	 * international marker (which {{#osm-place:jurisdiction}} renders as a
	 * non-empty cell). Config-shape failures fail OPEN (show the row).
	 */
	private static function osmHasData( EmbeddableContentConfig $config, Item $item, string $which ): bool {
		try {
			if ( $which === 'jurisdiction' ) {
				$props = $config->sourcePropertyIds();
				if ( self::stringValue( $item, $props['territorialJurisdictionOsm'] ?? null ) !== '' ) {
					return true;
				}
				return self::stringValue( $item, $props['international'] ?? null ) !== '';
			}
			$props = $config->personPropertyIds();
			$key = $which === 'birth' ? 'placeOfBirthOsm' : 'placeOfDeathOsm';
			return self::stringValue( $item, $props[$key] ?? null ) !== '';
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/** First string statement value of a property ('' when absent). */
	private static function stringValue( Item $item, ?string $propId ): string {
		if ( $propId === null || $propId === '' ) {
			return '';
		}
		try {
			$propertyId = new NumericPropertyId( $propId );
		} catch ( \Throwable $e ) {
			return '';
		}
		foreach ( $item->getStatements()->getByPropertyId( $propertyId ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof StringValue ) {
				return trim( $snak->getDataValue()->getValue() );
			}
		}
		return '';
	}

	/**
	 * The item: the explicit argument when it is a valid item id, otherwise
	 * the current page's sitelinked item (template use).
	 */
	private static function resolveItemId( Parser $parser, string $explicit ): ?ItemId {
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
		$revId = \EmbeddableContent\Spec\LatestRevision::id( WikibaseRepo::getEntityRevisionLookup( $services ), $itemId );
		$parser->getOutput()->addTemplate( $title, $title->getArticleID(), $revId );
	}
}
