<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * `{{#item-image:}}` parser function — the portrait/logo/image gallery of a
 * classic Person:/Collective:/Software:/FOSS: page.
 *
 * Renders EVERY `image` statement (across the configured person / collective
 * / FOSS / image property ids) as a one-at-a-time gallery with previous/next
 * buttons (resources/imagegallery.js + .css). One image renders without
 * navigation; no image renders nothing.
 *
 * The item is resolved from the CURRENT page's sitelink (or an explicit
 * `{{#item-image:Q42}}`). The item page is registered as a parser-cache
 * dependency so edits refresh the cell.
 *
 * Returns HTML (isHTML): the gallery is a small component, not wikitext.
 *
 * @license GPL-2.0-or-later
 */
final class ItemImage {

	/** Site id of the local sitelink group (also hardcoded in Hooks.php). */
	private const SITE_ID = 'wikibase';

	private const THUMB_WIDTH = 220;

	/**
	 * @param EmbeddableContentConfig $config injected via the hook closure
	 * @param Parser $parser
	 * @param mixed[] $args optional first arg = an explicit item id
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function onItemImage( EmbeddableContentConfig $config, Parser $parser, array $args ): array {
		try {
			return self::render( $config, $parser, $args );
		} catch ( \Throwable $e ) {
			// Defensive: a broken image must not take the whole page down.
			\MediaWiki\Logger\LoggerFactory::getInstance( 'EmbeddableContent' )->warning(
				'Item image failed: {error} @ {where}',
				[ 'error' => $e->getMessage(), 'where' => basename( $e->getFile() ) . ':' . $e->getLine() ]
			);
			return [
				'text' => '<span class="wb-item-image-debug">image-error: '
					. htmlspecialchars( $e->getMessage() ) . '</span>',
				'noparse' => false,
				'isHTML' => true,
			];
		}
	}

	private static function render( EmbeddableContentConfig $config, Parser $parser, array $args ): array {
		$empty = [
			'text' => '<span class="wb-item-image-debug">image-empty-args: '
				. htmlspecialchars( json_encode( $args ) ) . '</span>',
			'noparse' => false,
			'isHTML' => true,
		];
		$itemId = self::explicitItemId( $args );
		if ( $itemId === null ) {
			$title = $parser->getTitle();
			if ( $title === null || !$title->exists() || !$title->isContentPage() ) {
				return $empty;
			}
			$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( self::SITE_ID, $title->getPrefixedText() );
		}
		if ( $itemId === null ) {
			return $empty;
		}
		$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		if ( !$entity instanceof Item ) {
			return $empty;
		}
		self::registerCacheDependency( $parser, $itemId );

		$titles = self::fileTitles( $entity, $config );
		if ( $titles === [] ) {
			$debug = [];
			foreach ( array_unique( array_filter( [
				$config->personPropertyIds()['image'] ?? null,
				$config->collectivePropertyIds()['image'] ?? null,
				$config->fossPropertyIds()['image'] ?? null,
				$config->imagePropertyIds()['image'] ?? null,
			] ) ) as $pid ) {
				try {
					$id = new NumericPropertyId( $pid );
				} catch ( \Throwable $e ) {
					continue;
				}
				foreach ( $entity->getStatements()->getByPropertyId( $id ) as $stmt ) {
					$snak = $stmt->getMainSnak();
					if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof StringValue ) {
						$debug[] = $pid . '=' . $snak->getDataValue()->getValue();
					}
				}
			}
			return [
				'text' => '<span class="wb-item-image-debug">image-empty: '
					. htmlspecialchars( implode( ' | ', $debug ) ) . '</span>',
				'noparse' => false,
				'isHTML' => true,
			];
		}
		$parser->getOutput()->addModules( [ 'ext.embeddableContent.imagegallery' ] );
		return [
			'text' => self::galleryHtml( $titles ),
			'noparse' => false,
			'isHTML' => true,
		];
	}

	/** @param Title[] $titles */
	private static function galleryHtml( array $titles ): string {
		$repoGroup = MediaWikiServices::getInstance()->getRepoGroup();
		$slides = [];
		foreach ( $titles as $title ) {
			$file = $repoGroup->findFile( $title );
			if ( $file === false ) {
				continue;
			}
			$thumb = $file->createThumb( self::THUMB_WIDTH );
			$src = $thumb !== false && $thumb !== null ? $thumb->getUrl() : $file->getFullUrl();
			$label = htmlspecialchars( $title->getText() );
			$slides[] = '<figure class="wb-image-gallery-slide">'
				. '<a href="' . htmlspecialchars( $title->getFullURL() ) . '"'
				. ' target="_blank" rel="noopener noreferrer">'
				. '<img src="' . htmlspecialchars( $src ) . '" width="' . self::THUMB_WIDTH . '"'
				. ' alt="' . $label . '" loading="lazy">'
				. '</a></figure>';
		}
		if ( $slides === [] ) {
			$names = array_map( static fn ( Title $t ): string => $t->getPrefixedText(), $titles );
			return '<span class="wb-item-image-debug">image-nofile: '
				. htmlspecialchars( implode( ' | ', $names ) ) . '</span>';
		}
		$multiple = count( $slides ) > 1;
		$html = '<div class="wb-image-gallery" data-count="' . count( $slides ) . '">'
			. '<div class="wb-image-gallery-track">' . implode( '', $slides ) . '</div>';
		if ( $multiple ) {
			$html .= '<button type="button" class="wb-image-gallery-prev" aria-label="Previous image">‹</button>'
				. '<button type="button" class="wb-image-gallery-next" aria-label="Next image">›</button>';
		}
		return $html . '</div>';
	}

	/**
	 * Explicit item id from the first function argument, or null.
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
	 * File: titles from every configured image property (all statements, not
	 * just the first property/statement).
	 *
	 * @return Title[]
	 */
	private static function fileTitles( Item $item, EmbeddableContentConfig $config ): array {
		$propIds = array_values( array_unique( array_filter( [
			$config->personPropertyIds()['image'] ?? null,
			$config->collectivePropertyIds()['image'] ?? null,
			$config->fossPropertyIds()['image'] ?? null,
			$config->imagePropertyIds()['image'] ?? null,
		] ) ) );
		$titles = [];
		$seen = [];
		foreach ( $propIds as $propId ) {
			try {
				$propertyId = new NumericPropertyId( $propId );
			} catch ( \Throwable $e ) {
				continue;
			}
			foreach ( $item->getStatements()->getByPropertyId( $propertyId ) as $statement ) {
				$snak = $statement->getMainSnak();
				if ( !$snak instanceof PropertyValueSnak || !$snak->getDataValue() instanceof StringValue ) {
					continue;
				}
				$title = self::fileTitleFromUrl( (string)$snak->getDataValue()->getValue() );
				if ( $title === null ) {
					continue;
				}
				$key = $title->getPrefixedText();
				if ( !isset( $seen[$key] ) ) {
					$seen[$key] = true;
					$titles[] = $title;
				}
			}
		}
		return $titles;
	}

	private static function fileTitleFromUrl( string $url ): ?Title {
		$parts = parse_url( $url );
		if ( !is_array( $parts ) ) {
			return null;
		}
		$candidate = '';
		if ( isset( $parts['query'] ) ) {
			parse_str( (string)$parts['query'], $query );
			$candidate = is_string( $query['title'] ?? null ) ? $query['title'] : '';
		}
		if ( $candidate === '' ) {
			$path = (string)( $parts['path'] ?? '' );
			$candidate = $path !== '' ? basename( $path ) : '';
		}
		if ( $candidate === '' || $candidate === '/' ) {
			return null;
		}
		$candidate = rawurldecode( $candidate );
		if ( stripos( $candidate, 'File:' ) === 0 ) {
			$title = Title::newFromText( $candidate );
		} else {
			$title = Title::makeTitle( NS_FILE, $candidate );
		}
		return $title !== null && $title->inNamespace( NS_FILE ) ? $title : null;
	}

	private static function registerCacheDependency( Parser $parser, EntityId $itemId ): void {
		$services = MediaWikiServices::getInstance();
		$title = WikibaseRepo::getEntityTitleStoreLookup( $services )->getTitleForId( $itemId );
		if ( $title === null || !$title->exists() ) {
			return;
		}
		$revId = \EmbeddableContent\Spec\LatestRevision::id(
			WikibaseRepo::getEntityRevisionLookup( $services ),
			$itemId
		);
		$parser->getOutput()->addTemplate( $title, $title->getArticleID(), $revId );
	}
}
