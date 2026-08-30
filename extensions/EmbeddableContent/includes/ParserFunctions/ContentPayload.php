<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use DataValues\MonolingualTextValue;
use DataValues\StringValue;
use EmbeddableContent\Content\PayloadCodec;
use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Parser\Parser;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * `{{#content:}}` parser function — the on-wiki decoder for content-item
 * payloads (issue #6 §8 escalation, option A: escape-at-rest,
 * decode-at-render).
 *
 * Content items store their payload backslash-escaped (newlines, tabs,
 * carriage returns and backslashes encoded as `\n`, `\t`, `\r`, `\\` — the
 * wiki's string/monolingualtext values reject the raw whitespace), so a raw
 * `{{#statements:P3}}` shows the escaped text. This function renders the
 * item's payload property decoded, the drop-in for that display:
 *
 *   `{{#content:Q1085}}`          — the payload of the named content item
 *   `{{#content:}}`               — the current page's sitelinked item
 *   `<pre>{{#content:Q1085}}</pre>` — multi-line code renders as typed
 *
 * The kind is detected from the item's class (quotation / code / math); the
 * payload property id comes from the instance config, so no property ids are
 * hardcoded. A quotation's payload is monolingual text: the page language is
 * preferred, with the English term as fallback.
 *
 * Returns decoded TEXT (no formatting) so pages wrap it as they like; the
 * embed renderers (Special:Embed / action=embed) decode separately.
 *
 * The item page is registered as a parser-cache dependency (the
 * `{{#item-image:}}` pattern), so editing the item re-renders every page
 * showing its content.
 *
 * @license GPL-2.0-or-later
 */
final class ContentPayload {

	/** Site id of the local sitelink group (also hardcoded in Hooks.php). */
	private const SITE_ID = 'wikibase';

	/**
	 * @param EmbeddableContentConfig $config injected via the hook closure
	 * @param Parser $parser
	 * @param mixed[] $args optional first arg = an explicit item id
	 * @return array{text:string,noparse:bool,isHTML:bool}
	 */
	public static function onContent( EmbeddableContentConfig $config, Parser $parser, array $args ): array {
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

		// Editing the item must re-render every page showing its content.
		self::registerCacheDependency( $parser, $itemId );

		$kind = self::kindOf( $entity, $config );
		if ( $kind === null ) {
			return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
		}
		$payloadProperty = $config->payloadPropertyIds()[$kind];
		$text = self::payloadText( $entity, $payloadProperty, $kind, $parser );
		return [ 'text' => $text, 'noparse' => false, 'isHTML' => false ];
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

	/** The content kind the item is classified under, or null. */
	private static function kindOf( Item $item, EmbeddableContentConfig $config ): ?string {
		$instanceOf = $config->instanceOfPropertyId();
		$classIds = $config->classIds();
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak
				|| $snak->getPropertyId()->getSerialization() !== $instanceOf
			) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( !$value instanceof EntityIdValue ) {
				continue;
			}
			$classId = $value->getEntityId()->getSerialization();
			$kind = array_search( $classId, $classIds, true );
			if ( $kind !== false ) {
				return $kind;
			}
		}
		return null;
	}

	/** The item's decoded payload for the kind, language-aware for quotations. */
	private static function payloadText(
		Item $item,
		string $payloadProperty,
		string $kind,
		Parser $parser
	): string {
		$payloads = [];
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak
				|| $snak->getPropertyId()->getSerialization() !== $payloadProperty
			) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof MonolingualTextValue ) {
				$payloads[$value->getLanguageCode()] = PayloadCodec::decode( $value->getText() );
			} elseif ( $value instanceof StringValue ) {
				$payloads[''] = PayloadCodec::decode( $value->getValue() );
			}
		}

		if ( $kind === 'quotation' ) {
			$pageLanguage = $parser->getTargetLanguage()?->getCode() ?? 'en';
			return $payloads[$pageLanguage] ?? $payloads['en'] ?? ( reset( $payloads ) ?: '' );
		}
		return $payloads[''] ?? '';
	}

	/**
	 * Parser-cache dependency on the item page (the `{{#item-image:}}`
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
