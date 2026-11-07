<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Spec\EntityLabelText;
use EmbeddableContent\Spec\ItemPageTitle;
use EmbeddableContent\Spec\LatestRevision;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * `{{#image-facts:}}` — renders a File: page's Licensing/Attribution blocks
 * from the sitelinked image item's statements (semantic-first).
 *
 * The upload form writes this function instead of static attribution text;
 * the backfill tool rewrites the existing static blocks. Editing the item
 * (Special:UpdateFile, action=imagefacts, wbeditentity) therefore updates
 * the displayed attribution with no File-page edit. The item is registered
 * as a parser-cache dependency.
 *
 * The function resolves the current File page's `wikibase` sitelink, or an
 * explicit `{{#image-facts:Q42}}` argument.
 *
 * @license GPL-2.0-or-later
 */
final class ImageFacts {

	/**
	 * @param array<int,string> $args
	 * @return array{0:string,1:array<string,mixed>}
	 */
	public static function onImageFacts(
		EmbeddableContentConfig $config,
		Parser $parser,
		array $args
	): array {
		$itemId = self::resolveItemId( $parser, $args );
		if ( $itemId === null ) {
			return [ '', 'noparse' => true ];
		}
		$item = self::lookupItem( $itemId );
		if ( !$item instanceof Item ) {
			return [ '', 'noparse' => true ];
		}
		$wikitext = self::render( $config, $parser, $item );
		if ( $wikitext === '' ) {
			return [ '', 'noparse' => true ];
		}
		self::registerCacheDependency( $parser, $itemId );
		return [ $wikitext, 'noparse' => false ];
	}

	private static function resolveItemId( Parser $parser, array $args ): ?ItemId {
		$explicit = trim( (string)( $args[0] ?? '' ) );
		if ( preg_match( '/^Q[1-9]\d*$/i', $explicit ) === 1 ) {
			return new ItemId( strtoupper( $explicit ) );
		}
		$title = $parser->getTitle();
		if ( $title === null || $title->getNamespace() !== NS_FILE ) {
			return null;
		}
		try {
			return WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( 'wikibase', $title->getPrefixedText() );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private static function lookupItem( ItemId $itemId ): ?Item {
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $entity instanceof Item ? $entity : null;
	}

	private static function render(
		EmbeddableContentConfig $config,
		Parser $parser,
		Item $item
	): string {
		$props = $config->imagePropertyIds();
		$provenance = $config->provenancePropertyIds();
		$licenseLines = self::entityLinks( $item, $props['license'] ?? null );
		$authors = self::entityLinks( $item, $provenance['attributedTo'] ?? null );
		if ( $authors === [] ) {
			$authors = self::stringValues( $item, $props['imageAuthor'] ?? null );
		}
		$licenseInfo = self::stringValues( $item, $props['imageLicenseInfo'] ?? null );
		$sources = self::entityLinks( $item, $provenance['source'] ?? null );
		if ( $sources === [] ) {
			$sources = self::stringValues( $item, $props['imageSource'] ?? null );
		}
		$sourceUrls = self::stringValues( $item, $provenance['sourceUrl'] ?? null );

		$attribution = [];
		if ( $authors !== [] ) {
			$attribution[] = wfMessage( 'embeddablecontent-upload-attribution-author' )
				->inContentLanguage()->text() . ': ' . implode( ', ', $authors );
		}
		if ( $licenseInfo !== [] ) {
			$attribution[] = wfMessage( 'embeddablecontent-upload-attribution-licenseinfo' )
				->inContentLanguage()->text() . ': ' . implode( ', ', $licenseInfo );
		}
		if ( $sources !== [] ) {
			$attribution[] = wfMessage( 'embeddablecontent-upload-attribution-source' )
				->inContentLanguage()->text() . ': ' . implode( ', ', $sources );
		}
		if ( $sourceUrls !== [] ) {
			$attribution[] = wfMessage( 'embeddablecontent-upload-attribution-sourceurl' )
				->inContentLanguage()->text() . ': ' . implode( ', ', $sourceUrls );
		}

		$licenseHeader = (string)wfMessage( 'license-header' )->inContentLanguage()->text();
		if ( $licenseHeader === '' ) {
			$licenseHeader = 'Licensing';
		}
		$attributionHeader = wfMessage( 'embeddablecontent-upload-attribution-header' )
			->inContentLanguage()->text();

		$out = '';
		if ( $licenseLines !== [] ) {
			$out .= '== ' . $licenseHeader . " ==\n" . implode( "\n", $licenseLines ) . "\n\n";
		}
		if ( $attribution !== [] ) {
			$out .= '== ' . $attributionHeader . " ==\n" . implode( "\n", $attribution ) . "\n";
		}
		return trim( $out );
	}

	/**
	 * `[[Item:Q…|label]]` or the item's classic page when it exists.
	 *
	 * @return string[] wikitext links
	 */
	private static function entityLinks( Item $item, ?string $propertyId ): array {
		if ( $propertyId === null || $propertyId === '' ) {
			return [];
		}
		$links = [];
		foreach ( self::entityIds( $item, $propertyId ) as $id ) {
			$label = self::label( $id );
			$target = ItemPageTitle::forItem( $id );
			$targetText = $target !== null && $target->exists()
				? $target->getPrefixedText()
				: 'Item:' . $id;
			$links[] = '[[' . $targetText . '|' . $label . ']]';
		}
		return $links;
	}

	/** @return string[] */
	private static function entityIds( Item $item, string $propertyId ): array {
		$ids = [];
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak ) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$ids[] = $value->getEntityId()->getSerialization();
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** @return string[] */
	private static function stringValues( Item $item, ?string $propertyId ): array {
		if ( $propertyId === null || $propertyId === '' ) {
			return [];
		}
		$values = [];
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof StringValue ) {
				$value = trim( (string)$snak->getDataValue()->getValue() );
				if ( $value !== '' ) {
					$values[] = $value;
				}
			}
		}
		return array_values( array_unique( $values ) );
	}

	private static function label( string $itemId ): string {
		$item = self::lookupItem( new ItemId( $itemId ) );
		$text = $item instanceof Item ? EntityLabelText::of( $item ) : null;
		return $text !== null && $text !== '' ? $text : $itemId;
	}

	private static function registerCacheDependency( Parser $parser, EntityId $itemId ): void {
		$services = MediaWikiServices::getInstance();
		try {
			$title = WikibaseRepo::getEntityTitleStoreLookup( $services )->getTitleForId( $itemId );
			$revId = LatestRevision::id( WikibaseRepo::getEntityRevisionLookup( $services ), $itemId );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( $title !== null && $title->exists() ) {
			$parser->getOutput()->addTemplate( $title, $title->getArticleID(), $revId );
		}
	}

}
