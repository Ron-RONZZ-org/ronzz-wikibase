<?php

declare( strict_types = 1 );

namespace EmbeddableContent\ParserFunctions;

use DataValues\MonolingualTextValue;
use DataValues\StringValue;
use EmbeddableContent\Content\ContentWikitext;
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
 * The function expands the payload into REGULAR WIKITEXT and lets the
 * consumer page handle formatting — it adds NO embed chrome (the reported
 * bug: the `.wb-embed` border rendered as a broken left line on an inline
 * math span). Quotations expand to their wikitext (wrap them in
 * `<blockquote>` on the page to quote them); math expands to a display
 * `$$…$$` span rendered by the instance's SimpleMathJax like every other
 * formula; code expands to a stock `<syntaxhighlight>` block. Quotations
 * expand to their wikitext followed by the attribution line (`-author,
 * ''[[Source:page|label]]''`, from the `attributed to` / `source`
 * statements) when they carry one. The parser
 * therefore parses the result in the page context — `[[File:…]]`, links,
 * `$…$` and tags all behave exactly as if written on the page.
 *
 * The embed SURFACES (`Special:Embed`, `api.php?action=embed`,
 * `Special:QuotationsOf`) keep their framed `.wb-embed` rendering; this
 * function is the on-wiki, unformatted path.
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

		// Expand to REGULAR WIKITEXT and let the consumer page format it —
		// no `.wb-embed` chrome (see the class docblock). MediaWiki parses
		// the result in the page context, so [[File:…]]/links/$…$/tags work
		// exactly as if written on the page.
		switch ( $kind ) {
			case 'quotation':
				$wikitext = ContentWikitext::quotation(
					$payload['text'],
					self::quotationAttribution( $entity, $config, $parser )
				);
				break;
			case 'code':
				$wikitext = ContentWikitext::code(
					$payload['text'],
					self::lexerFor( $entity, $config )
				);
				break;
			case 'math':
				// The accompanying note (rich wikitext) renders below the
				// expression by default; {{#content:Q42|noNote}} suppresses it.
				$note = ContentArgs::noNote( $args ) ? '' : self::noteFor( $entity, $config );
				$wikitext = ContentWikitext::math( $payload['text'], $note );
				break;
			default:
				return self::emptyResult();
		}

		// noparse=false + isHTML=false: the parser expands the returned text
		// as WIKITEXT (the default is noparse=TRUE, which would render links
		// and [[File:…]] literally). Same contract as the sibling parser
		// functions ({{#source-access:}}, {{#item-image:}}, …).
		return [ 'text' => $wikitext, 'noparse' => false, 'isHTML' => false ];
	}

	/** @return array{text:string,noparse:bool,isHTML:bool} */
	private static function emptyResult(): array {
		return [ 'text' => '', 'noparse' => false, 'isHTML' => false ];
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

	/**
	 * The item's accompanying note (math items): the decoded wikitext of the
	 * `note` property, or '' when the item carries none / the instance has no
	 * note vocabulary.
	 */
	private static function noteFor( Item $item, EmbeddableContentConfig $config ): string {
		$noteProperty = $config->notePropertyId();
		if ( $noteProperty === null ) {
			return '';
		}
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak
				|| $snak->getPropertyId()->getSerialization() !== $noteProperty
			) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof StringValue ) {
				return PayloadCodec::decode( $value->getValue() );
			}
		}
		return '';
	}

	/**
	 * The attribution line for a quotation: `-author, ''source''`. Authors
	 * are the `attributed to` entities rendered as their plain English
	 * label; sources are the `source` entities rendered italic and linked
	 * to their classic page (`''[[Source:Beloved (Book)|Beloved]]''`), or
	 * the plain italic label when the item has no page. Empty when the item
	 * carries neither statement.
	 */
	private static function quotationAttribution(
		Item $item,
		EmbeddableContentConfig $config,
		Parser $parser
	): string {
		$provenance = $config->provenancePropertyIds();
		$authors = [];
		foreach ( self::entityIds( $item, $provenance['attributedTo'] ?? null ) as $authorId ) {
			$authors[] = self::entityInfo( $authorId )['label'] ?? $authorId;
			self::registerEntityDependency( $parser, $authorId );
		}
		$sources = [];
		foreach ( self::entityIds( $item, $provenance['source'] ?? null ) as $sourceId ) {
			$sources[] = self::sourceWikitext( $sourceId, $parser );
		}
		return ContentWikitext::quotationAttribution( $authors, $sources );
	}

	/**
	 * The engine-id values of a wikibase-item property, in statement order.
	 *
	 * @return string[]
	 */
	private static function entityIds( Item $item, ?string $propertyId ): array {
		if ( $propertyId === null || $propertyId === '' ) {
			return [];
		}
		$ids = [];
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak
				|| $snak->getPropertyId()->getSerialization() !== $propertyId
			) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$ids[] = $value->getEntityId()->getSerialization();
			}
		}
		return $ids;
	}

	/**
	 * The italic wikitext for a source: a link to its classic page when it
	 * has one (`''[[Source:Beloved (Book)|Beloved]]''`), else the plain
	 * italic label (`''Beloved''`). Registers the source item as a
	 * parser-cache dependency. Never throws — a malformed/missing entity
	 * degrades to the bare id.
	 */
	private static function sourceWikitext( string $sourceId, Parser $parser ): string {
		$info = self::entityInfo( $sourceId );
		$label = $info['label'] ?? $sourceId;
		self::registerEntityDependency( $parser, $sourceId );
		if ( $info['page'] === null || $info['page'] === '' ) {
			return "''" . $label . "''";
		}
		return "''[[" . $info['page'] . '|' . $label . "]]''";
	}

	/**
	 * Best-effort parser-cache dependency on an item page (editing the
	 * author/source re-renders every consumer). A malformed id or a
	 * registration failure never breaks the parse.
	 */
	private static function registerEntityDependency( Parser $parser, string $itemId ): void {
		try {
			self::registerCacheDependency( $parser, new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			// Best-effort: a cache dependency must never break the parse.
		}
	}

	/**
	 * The en label + local classic-page title of an item, or nulls when it
	 * does not exist.
	 *
	 * @return array{label:?string,page:?string}
	 */
	private static function entityInfo( string $itemId ): array {
		try {
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return [ 'label' => null, 'page' => null ];
		}
		if ( !$item instanceof Item ) {
			return [ 'label' => null, 'page' => null ];
		}
		$term = $item->getLabels()->getByLanguage( 'en' );
		$siteLinks = $item->getSiteLinkList();
		// getBySiteId() THROWS when the site link is absent — guard it.
		$sitelink = $siteLinks->hasLinkWithSiteId( self::SITE_ID )
			? $siteLinks->getBySiteId( self::SITE_ID )
			: null;
		return [
			'label' => $term?->getText(),
			'page' => $sitelink?->getPageName(),
		];
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
