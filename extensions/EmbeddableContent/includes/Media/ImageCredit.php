<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Media;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Spec\EntityLabelText;
use EmbeddableContent\Spec\ImageCredit as ImageCreditSpec;
use EmbeddableContent\Spec\ItemPageTitle;
use EmbeddableContent\Spec\LatestRevision;
use MediaWiki\FileRepo\File\File;
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
 * Automatic credit line under an embedded thumbnail (ImageBeforeProduceHTML).
 *
 * On a content page, a framed / thumbnail image (`[[File:X|thumb|…]]`) gets
 * the image item's attribution facts appended to its caption:
 *
 *   Image: <author> · <license> · <source>
 *
 * Facts come from the sitelinked image item created by Special:Upload
 * (`ImageItemCreator`): the entity author (`attributed to`) or the free-text
 * `image author`, the license, and the entity/plain-text/URL source. An
 * image without an item (or without facts) renders no credit — this is a
 * best-effort enhancement, never a page error.
 *
 * Suppression: the `class=noCredit` option
 * (`[[File:X|thumb|Caption|class=noCredit]]`) is the canonical form — any
 * bare unknown option is swallowed by core's image parser. A bare
 * `[[File:X|noCredit]]` whose whole caption is the token is also accepted
 * (Spec\ImageCredit).
 *
 * The image item page is registered as a parser-cache dependency, so editing
 * the item re-renders every page showing the thumbnail (the ItemImage
 * pattern).
 *
 * @license GPL-2.0-or-later
 */
final class ImageCredit {

	/** Site id of the local sitelink group (also hardcoded in Hooks/ItemImage). */
	private const SITE_ID = 'wikibase';

	/** @var array<string,string> request-local label cache (a license/author repeats across thumbnails) */
	private static array $labelCache = [];

	/** @var array<string,Title|null> request-local Item: title cache */
	private static array $itemTitleCache = [];

	/**
	 * @param mixed $skin unused
	 * @param mixed $title LinkTarget/Title of the File: page
	 * @param mixed $file File|false
	 * @param array $frameParams frame parameters (caption/class are modified)
	 * @param array $handlerParams unused
	 * @param mixed $time unused
	 * @param mixed $res unused at hook entry
	 * @param mixed $parser Parser
	 * @param mixed $query unused
	 * @param mixed $widthOption unused
	 */
	public static function onImageBeforeProduceHTML(
		$skin,
		$title,
		$file,
		array &$frameParams,
		array &$handlerParams,
		$time,
		&$res,
		$parser,
		$query,
		$widthOption
	): bool {
		if ( !$parser instanceof Parser ) {
			return true;
		}
		$pageTitle = $parser->getTitle();
		if ( $pageTitle === null || !$pageTitle->isContentPage() ) {
			return true;
		}
		// class=noCredit — the canonical suppression form. Remove the token
		// from the class list on EVERY image (it must never leak into the
		// rendered HTML), and skip the credit when it was present.
		$class = (string)( $frameParams['class'] ?? '' );
		$stripped = ImageCreditSpec::stripNoCreditClass( $class );
		if ( $stripped['found'] ) {
			if ( $stripped['class'] === '' ) {
				unset( $frameParams['class'] );
			} else {
				$frameParams['class'] = $stripped['class'];
			}
			return true;
		}

		// Only images that render a caption area (thumb / framed / manual
		// thumb) can carry the credit line.
		$hasCaptionArea = isset( $frameParams['thumbnail'] )
			|| isset( $frameParams['manualthumb'] )
			|| isset( $frameParams['framed'] );
		if ( !$hasCaptionArea ) {
			return true;
		}
		if ( !$title instanceof Title ) {
			$title = $title instanceof \MediaWiki\Title\LinkTarget
				? Title::newFromLinkTarget( $title )
				: null;
		}
		if ( !$title instanceof Title || !$title->inNamespace( NS_FILE ) || !$title->exists() ) {
			return true;
		}
		if ( !$file instanceof File || !$file->exists() ) {
			return true;
		}
		// Image thumbnails only: audio/video/PDF/CSV embeds keep their own
		// caption area without a photo credit.
		if ( !in_array( $file->getMediaType(), [ MEDIATYPE_BITMAP, MEDIATYPE_DRAWING ], true ) ) {
			return true;
		}

		// Backwards-compatible bare form: the whole caption is the token.
		$caption = (string)( $frameParams['caption'] ?? '' );
		if ( ImageCreditSpec::isNoCreditCaption( $caption ) ) {
			$frameParams['caption'] = '';
			return true;
		}

		$credit = self::creditHtml( $title, $parser );
		if ( $credit !== '' ) {
			$frameParams['caption'] = $caption . $credit;
		}
		return true;
	}

	/**
	 * The credit HTML for a File: page, or '' when the file has no image item
	 * or the item carries no author/license/source.
	 */
	private static function creditHtml( Title $fileTitle, Parser $parser ): string {
		$itemId = self::itemIdForFile( $fileTitle );
		if ( $itemId === null ) {
			return '';
		}
		$item = self::itemLookup( $itemId );
		if ( !$item instanceof Item ) {
			return '';
		}
		$config = self::config();
		if ( $config === null ) {
			return '';
		}
		$props = $config->imagePropertyIds();
		$provenance = $config->provenancePropertyIds();
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		$parts = [];
		$authors = self::entityLinks( $item, $provenance['attributedTo'] ?? null, $linkRenderer );
		if ( $authors === [] ) {
			$authors = array_map(
				static fn ( string $text ): string => htmlspecialchars( $text ),
				self::stringValues( $item, $props['imageAuthor'] ?? null )
			);
		}
		if ( $authors !== [] ) {
			$parts[] = implode( ', ', $authors );
		}

		$licenses = self::entityLinks( $item, $props['license'] ?? null, $linkRenderer );
		if ( $licenses !== [] ) {
			$parts[] = implode( ', ', $licenses );
		}

		$sources = self::entityLinks( $item, $provenance['source'] ?? null, $linkRenderer );
		if ( $sources === [] ) {
			$sources = array_map(
				static fn ( string $text ): string => htmlspecialchars( $text ),
				self::stringValues( $item, $props['imageSource'] ?? null )
			);
		}
		if ( $sources === [] ) {
			$urls = self::stringValues( $item, $provenance['sourceUrl'] ?? null );
			if ( $urls !== [] ) {
				$sources[] = self::externalLink( $urls[0] );
			}
		}
		if ( $sources !== [] ) {
			$parts[] = implode( ', ', $sources );
		}

		$prefix = wfMessage( 'embeddablecontent-imagecredit-prefix' )
			->inLanguage( $parser->getTargetLanguage() )
			->text();
		$line = ImageCreditSpec::line( $parts, $prefix );
		if ( $line === '' ) {
			return '';
		}
		$parser->getOutput()->addModuleStyles( [ 'ext.embeddableContent.imagecredit' ] );
		self::registerCacheDependency( $parser, $itemId );
		return '<div class="wb-image-credit">' . $line . '</div>';
	}

	/** The sitelinked item of a File: page, or null. */
	private static function itemIdForFile( Title $fileTitle ): ?ItemId {
		try {
			return WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( self::SITE_ID, $fileTitle->getPrefixedText() );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** @return Item|null */
	private static function itemLookup( EntityId $itemId ) {
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $entity instanceof Item ? $entity : null;
	}

	/**
	 * Linked labels of an item-valued property: the classic page when the
	 * item has one, else the Item: page, else the bare label.
	 *
	 * @return string[] escaped HTML fragments
	 */
	private static function entityLinks( Item $item, ?string $propertyId, $linkRenderer ): array {
		if ( $propertyId === null || $propertyId === '' ) {
			return [];
		}
		$links = [];
		foreach ( self::entityIds( $item, $propertyId ) as $id ) {
			$target = ItemPageTitle::forItem( $id );
			if ( $target === null || !$target->exists() ) {
				$target = self::itemTitle( $id );
			}
			$label = self::label( $id );
			if ( $target === null ) {
				$links[] = htmlspecialchars( $label );
				continue;
			}
			$links[] = $linkRenderer->makeKnownLink( $target, $label );
		}
		return $links;
	}

	/** @return string[] the item-id serializations of an item-valued property */
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

	/** @return string[] the string values of a property */
	private static function stringValues( Item $item, ?string $propertyId ): array {
		if ( $propertyId === null || $propertyId === '' ) {
			return [];
		}
		$values = [];
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof StringValue ) {
				$values[] = $snak->getDataValue()->getValue();
			}
		}
		return $values;
	}

	/** The Item: page title of an item id, or null. */
	private static function itemTitle( string $itemId ): ?Title {
		if ( array_key_exists( $itemId, self::$itemTitleCache ) ) {
			return self::$itemTitleCache[$itemId];
		}
		try {
			$title = WikibaseRepo::getEntityTitleStoreLookup(
				MediaWikiServices::getInstance()
			)->getTitleForId( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			$title = null;
		}
		self::$itemTitleCache[$itemId] = $title;
		return $title;
	}

	/** The item's label (English preferred), or the raw id. */
	private static function label( string $itemId ): string {
		if ( array_key_exists( $itemId, self::$labelCache ) ) {
			return self::$labelCache[$itemId];
		}
		$label = $itemId;
		$item = self::itemLookup( new ItemId( $itemId ) );
		if ( $item instanceof Item ) {
			$text = EntityLabelText::of( $item );
			if ( $text !== null && $text !== '' ) {
				$label = $text;
			}
		}
		self::$labelCache[$itemId] = $label;
		return $label;
	}

	/** A safe external link for a source URL. */
	private static function externalLink( string $url ): string {
		$escaped = htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		return '<a class="external" rel="nofollow" href="' . $escaped . '">' . $escaped . '</a>';
	}

	/** The configured instance config, or null when the service is absent. */
	private static function config(): ?EmbeddableContentConfig {
		try {
			$config = MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $config instanceof EmbeddableContentConfig ? $config : null;
	}

	/**
	 * Parser-cache dependency on the image item page (the ItemImage pattern):
	 * editing the item re-renders every page showing the thumbnail.
	 */
	private static function registerCacheDependency( Parser $parser, EntityId $itemId ): void {
		$services = MediaWikiServices::getInstance();
		$title = WikibaseRepo::getEntityTitleStoreLookup( $services )->getTitleForId( $itemId );
		if ( $title === null || !$title->exists() ) {
			return;
		}
		$revId = LatestRevision::id( WikibaseRepo::getEntityRevisionLookup( $services ), $itemId );
		$parser->getOutput()->addTemplate( $title, $title->getArticleID(), $revId );
	}

}
