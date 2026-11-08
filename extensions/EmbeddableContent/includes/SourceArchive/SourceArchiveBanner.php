<?php

declare( strict_types = 1 );

namespace EmbeddableContent\SourceArchive;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\SourceFieldMap;
use EmbeddableContent\Spec\ArchiveCover;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * The prominent disclaimer banner on a `Source:<label>/archivedText` page.
 *
 * Server-rendered by Hooks::onBeforePageDisplay (OutputPage, not parser
 * output) so it can be dismissed for the current view only — a page refresh
 * shows it again — and it prints with the page (Save-as-PDF / Ctrl-P).
 *
 * The "(Media content: view on original URL)" note inside the archived text
 * links the same source URL; the banner repeats it prominently together with
 * the archive timestamp (the page's first revision).
 *
 * @license GPL-2.0-or-later
 */
final class SourceArchiveBanner {

	public static function isArchivePage( Title $title ): bool {
		return SourceArchive::isArchiveTitle( $title );
	}

	/**
	 * @param Title $title the archive page (`…/archivedText`)
	 */
	public static function html(
		Title $title,
		IContextSource $context,
		EmbeddableContentConfig $config
	): string {
		$revision = MediaWikiServices::getInstance()->getRevisionStore()
			->getFirstRevision( $title );
		$timestamp = $revision !== null ? $revision->getTimestamp() : null;

		$url = self::sourceUrl( $title, $config );
		$link = $url !== null
			? Html::element(
				'a',
				[ 'href' => $url, 'class' => 'external', 'rel' => 'nofollow' ],
				$url
			)
			// No URL recorded: name the source page rather than emit a dead
			// "at $1" — the banner still carries the disclaimer.
			: htmlspecialchars( $title->getBaseTitle()->getPrefixedText() );

		$dateHtml = '';
		if ( $timestamp !== null ) {
			$dateHtml = Html::element(
				'time',
				[ 'datetime' => wfTimestamp( TS_ISO_8601, $timestamp ) ],
				$context->getLanguage()->userTimeAndDate( $timestamp, $context->getUser() )
			);
		}

		$message = $context->msg( 'embeddablecontent-source-archive-banner' )
			->rawParams( $link, $dateHtml )
			->parse();

		$dismiss = $context->msg( 'embeddablecontent-source-archive-dismiss' )->text();

		return Html::rawElement(
			'div',
			[ 'class' => 'wb-sourcearchive-banner', 'role' => 'note' ],
			Html::rawElement(
				'div',
				[ 'class' => 'wb-sourcearchive-body' ],
				$message
				. Html::element( 'br' )
				. Html::element(
					'a',
					[ 'href' => '#', 'class' => 'wb-sourcearchive-dismiss' ],
					$dismiss
				)
			)
		);
	}

	/**
	 * The data the client-side print cover needs for an archive page:
	 *
	 *  - `title`    the source page's title WITHOUT the namespace and without
	 *               the class-disambiguation suffix;
	 *  - `class`    the source class label ("Webpage", "Scholarly article"), or
	 *               '' when it cannot be resolved;
	 *  - `date`     the archive's first revision in the reader's time zone
	 *               (localized time and date, the banner's format);
	 *  - `url`      the external URL the page was archived from, or '';
	 *  - `filename` the source title with the class suffix, for the
	 *               Save-as-PDF suggestion (without the `/archivedText`).
	 *
	 * @return array{title:string,class:string,date:string,url:string,filename:string}|null
	 *  null when $title is not an archive page
	 */
	public static function coverData(
		Title $title,
		IContextSource $context,
		EmbeddableContentConfig $config
	): ?array {
		if ( !self::isArchivePage( $title ) ) {
			return null;
		}

		$baseText = $title->getBaseTitle()->getBaseText();
		$item = self::sourceItem( $title );
		$class = $item !== null
			? self::classLabel( $item, $config, $context )
			: '';
		if ( $class === '' ) {
			$class = ArchiveCover::suffix( $baseText );
		}

		$coverTitle = ArchiveCover::title( $baseText, $class );
		$displayTitle = $coverTitle . ( $class !== '' ? ' (' . $class . ')' : '' );

		$revision = MediaWikiServices::getInstance()->getRevisionStore()
			->getFirstRevision( $title );
		$date = $revision !== null
			? $context->getLanguage()->userTimeAndDate( $revision->getTimestamp(), $context->getUser() )
			: '';

		return [
			'title' => $coverTitle,
			'class' => $class,
			'date' => $date,
			'url' => $item !== null ? ( self::urlFromItem( $item, $config ) ?? '' ) : '',
			'filename' => $displayTitle,
		];
	}

	/** The class label of the archived source item, or '' when unresolvable. */
	private static function classLabel(
		Item $item,
		EmbeddableContentConfig $config,
		IContextSource $context
	): string {
		$instanceOf = $config->instanceOfPropertyId();
		$keyByQid = array_flip( $config->sourceClasses() );
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak
				|| $snak->getPropertyId()->getSerialization() !== $instanceOf
			) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$value = $value->getEntityId();
			}
			if ( !$value instanceof ItemId ) {
				continue;
			}
			$key = $keyByQid[$value->getSerialization()] ?? null;
			if ( $key === null ) {
				continue;
			}
			$message = 'embeddablecontent-source-class-' . SourceFieldMap::formKey( $key );
			return $context->msg( $message )->exists()
				? $context->msg( $message )->text()
				: $key;
		}
		return '';
	}

	/** The source item sitelinked to the archive's base page, or null. */
	private static function sourceItem( Title $title ): ?Item {
		$base = $title->getBaseTitle();
		$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
			->getItemIdForLink( 'wikibase', $base->getPrefixedText() );
		if ( $itemId === null ) {
			return null;
		}
		$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		return $entity instanceof Item ? $entity : null;
	}

	/** The external URL the page was archived from, if the item records one. */
	private static function sourceUrl( Title $title, EmbeddableContentConfig $config ): ?string {
		$item = self::sourceItem( $title );
		return $item !== null ? self::urlFromItem( $item, $config ) : null;
	}

	/** The first `url` / `accessUrl` statement of a source item. */
	private static function urlFromItem( Item $item, EmbeddableContentConfig $config ): ?string {
		$props = $config->sourcePropertyIds();
		foreach ( [ 'url', 'accessUrl' ] as $key ) {
			$propertyId = $props[$key] ?? null;
			if ( $propertyId === null ) {
				continue;
			}
			foreach ( $item->getStatements() as $statement ) {
				$snak = $statement->getMainSnak();
				if ( !$snak instanceof PropertyValueSnak
					|| $snak->getPropertyId()->getSerialization() !== $propertyId
				) {
					continue;
				}
				$value = $snak->getDataValue();
				if ( $value instanceof StringValue
					&& preg_match( '#^https?://#i', $value->getValue() )
				) {
					return $value->getValue();
				}
			}
		}
		return null;
	}
}
