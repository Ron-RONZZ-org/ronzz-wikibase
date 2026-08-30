<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use DataValues\MonolingualTextValue;
use DataValues\StringValue;
use EmbeddableContent\Content\CodeRenderer;
use EmbeddableContent\Content\FragmentSanitizer;
use EmbeddableContent\Content\MathRenderer;
use EmbeddableContent\Content\PayloadCodec;
use EmbeddableContent\Content\QuoteRenderer;
use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Parser\Parser;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * `{{#content:}}` parser function — the on-wiki renderer for content-item
 * payloads (issue #6 §8 escalation, option A: escape-at-rest,
 * decode-at-render).
 *
 * Content items store their payload backslash-escaped (newlines, tabs,
 * carriage returns and backslashes encoded as `\n`, `\t`, `\r`, `\\` — the
 * wiki's string/monolingualtext values reject the raw whitespace). This
 * function decodes the payload and renders it as the SAME HTML fragment the
 * embed surfaces produce, so on-wiki pages show content faithfully:
 *
 *   `{{#content:Q1129}}` — the payload of the named content item
 *   `{{#content:}}`      — the current page's sitelinked item
 *
 * Quotations render as a `<blockquote>`, code as a SyntaxHighlight
 * (Pygments) block with an escaped `<pre>` fallback, math as a KaTeX span
 * (rendered client-side by the embed module, escaped TeX fallback without
 * JS). The result is returned as HTML (`noparse`, `isHTML`), which is why
 * it also works verbatim — the alternative to `{{#statements:P3}}`, whose
 * output is re-parsed as wikitext and shows the raw escaped value.
 *
 * The embed resource module (styles + KaTeX/highlight scripts) is loaded on
 * the page so the fragment looks and renders like the embed surface.
 *
 * The kind is detected from the item's class (quotation / code / math); the
 * payload property id comes from the instance config, so no property ids are
 * hardcoded. A quotation's payload is monolingual text: the page language is
 * preferred, with the English term as fallback.
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

	/** The embed resource module: wb-embed styles + KaTeX/highlight scripts. */
	private const EMBED_MODULE = 'ext.embeddableContent.embed';

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
				return self::emptyResult();
			}
			$itemId = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( self::SITE_ID, $title->getPrefixedText() );
		}
		if ( $itemId === null ) {
			return self::emptyResult();
		}

		$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		if ( !$entity instanceof Item ) {
			return self::emptyResult();
		}

		// Editing the item must re-render every page showing its content.
		self::registerCacheDependency( $parser, $itemId );

		$kind = self::kindOf( $entity, $config );
		if ( $kind === null ) {
			return self::emptyResult();
		}
		$payloadProperty = $config->payloadPropertyIds()[$kind];
		$payload = self::payloadFor( $entity, $payloadProperty, $kind, $parser );
		if ( $payload['text'] === '' ) {
			return self::emptyResult();
		}

		// Render the fragment like the embed surface, and load the module
		// that styles it and renders math client-side.
		$parser->getOutput()->addModuleStyles( self::EMBED_MODULE );
		$parser->getOutput()->addModules( self::EMBED_MODULE );

		$sanitizer = new FragmentSanitizer();
		switch ( $kind ) {
			case 'quotation':
				$html = ( new QuoteRenderer( $sanitizer, $config ) )->render(
					$payload['text'],
					$payload['lang'] ?? 'en'
				);
				break;
			case 'code':
				$html = ( new CodeRenderer( $sanitizer, $config ) )->render(
					$payload['text'],
					self::lexerFor( $entity, $config )
				);
				break;
			case 'math':
				$html = ( new MathRenderer( $sanitizer ) )->render( $payload['text'] );
				break;
			default:
				return self::emptyResult();
		}

		return [ 'text' => $html, 'noparse' => true, 'isHTML' => true ];
	}

	/** @return array{text:string,noparse:bool,isHTML:bool} */
	private static function emptyResult(): array {
		return [ 'text' => '', 'noparse' => true, 'isHTML' => true ];
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

	/**
	 * The item's decoded payload for the kind, language-aware for
	 * quotations. Returns the text and, for quotations, the chosen language.
	 *
	 * @return array{text:string,lang?:string}
	 */
	private static function payloadFor(
		Item $item,
		string $payloadProperty,
		string $kind,
		Parser $parser
	): array {
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
			$lang = $pageLanguage;
			if ( isset( $payloads[$lang] ) ) {
				return [ 'text' => $payloads[$lang], 'lang' => $lang ];
			}
			if ( isset( $payloads['en'] ) ) {
				return [ 'text' => $payloads['en'], 'lang' => 'en' ];
			}
			$first = reset( $payloads );
			if ( $first !== false ) {
				$lang = (string)array_key_first( $payloads );
				return [ 'text' => $first, 'lang' => $lang ];
			}
			return [ 'text' => '' ];
		}
		return [ 'text' => $payloads[''] ?? '' ];
	}

	/** The Pygments lexer for the item's programming-language statement. */
	private static function lexerFor( Item $item, EmbeddableContentConfig $config ): string {
		$programmingLanguage = $config->programmingLanguagePropertyId();
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak
				|| $snak->getPropertyId()->getSerialization() !== $programmingLanguage
			) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$lexer = $config->lexerForItemId( $value->getEntityId()->getSerialization() );
				if ( $lexer !== null ) {
					return $lexer;
				}
			}
		}
		return 'text';
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
