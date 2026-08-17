<?php

declare( strict_types = 1 );

namespace EmbeddableContent;

use MediaWiki\Output\OutputPage;
use MediaWiki\SpecialPage\SpecialPage;
use Wikibase\DataModel\Entity\Item;
use Wikibase\Repo\WikibaseRepo;

/**
 * Entry-point hooks: entity-page gadget (copy embed / copy citation) and the
 * oEmbed discovery <link> on item pages (issue #6 §4.3, §4.4).
 *
 * @license GPL-2.0-or-later
 */
class Hooks {

	public static function onBeforePageDisplay( OutputPage $out, $skin ): void {
		$title = $out->getTitle();
		if ( $title === null ) {
			return;
		}

		$namespaceLookup = WikibaseRepo::getEntityNamespaceLookup();
		$itemNamespace = $namespaceLookup->getEntityNamespace( Item::ENTITY_TYPE );
		if ( $itemNamespace === false || $title->getNamespace() !== $itemNamespace ) {
			return;
		}

		$entityId = self::parseItemId( $title->getText() );
		if ( $entityId === null ) {
			return;
		}

		$out->addModules( 'ext.embeddableContent.gadget' );

		$oembedUrl = SpecialPage::getTitleFor( 'Embed', 'oembed' )
			->getFullURL( [ 'url' => $title->getFullURL() ] );
		$out->addLink( [
			'rel' => 'alternate',
			'type' => 'application/json+oembed',
			'href' => $oembedUrl,
		] );
	}

	private static function parseItemId( string $text ): ?\Wikibase\DataModel\Entity\ItemId {
		try {
			$id = WikibaseRepo::getEntityIdParser()->parse( $text );
			return $id instanceof \Wikibase\DataModel\Entity\ItemId ? $id : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
