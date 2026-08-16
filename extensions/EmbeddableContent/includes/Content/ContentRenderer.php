<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Context\IContextSource;
use Wikimedia\ObjectCache\BagOStuff;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\Lib\Store\EntityRevisionLookup;

/**
 * Shared embed renderer: conformance check, payload extraction, language
 * negotiation, per-kind rendering, JSON-LD, provenance, revId-keyed cache.
 *
 * No SPARQL per request — everything comes from the entity via the
 * WikibaseRepo service layer (issue #6, §4.2).
 *
 * @license GPL-2.0-or-later
 */
class ContentRenderer {

	public const CACHE_TTL = 2592000; // 30 days — revId-keyed, so content is immutable

	/** @var EmbeddableContentConfig */
	private $config;

	/** @var EntityLookup */
	private $entityLookup;

	/** @var EntityRevisionLookup */
	private $revisionLookup;

	/** @var BagOStuff */
	private $cache;

	/** @var FragmentSanitizer */
	private $sanitizer;

	/** @var QuoteRenderer */
	private $quoteRenderer;

	/** @var CodeRenderer */
	private $codeRenderer;

	/** @var MathRenderer */
	private $mathRenderer;

	public function __construct(
		EmbeddableContentConfig $config,
		EntityLookup $entityLookup,
		EntityRevisionLookup $revisionLookup,
		BagOStuff $cache,
		?IContextSource $context = null
	) {
		$this->config = $config;
		$this->entityLookup = $entityLookup;
		$this->revisionLookup = $revisionLookup;
		$this->cache = $cache;
		$this->sanitizer = new FragmentSanitizer();
		$this->quoteRenderer = new QuoteRenderer( $this->sanitizer, $config );
		$this->codeRenderer = new CodeRenderer( $this->sanitizer, $config );
		$this->mathRenderer = new MathRenderer( $this->sanitizer );
	}

	/**
	 * @param string[] $acceptLanguages preferred languages in order (from Accept-Language)
	 *
	 * @throws RenderException
	 */
	public function render(
		ItemId $id,
		string $format,
		?string $lang = null,
		?int $revId = null,
		array $acceptLanguages = []
	): RenderResult {
		$entityRevision = null;
		if ( $revId !== null && $revId > 0 ) {
			$entityRevision = $this->revisionLookup->getEntityRevision( $id, $revId );
		}
		$item = $entityRevision ? $entityRevision->getEntity() : $this->entityLookup->getEntity( $id );
		if ( !$item instanceof Item ) {
			throw new RenderException( "No item $id", 'entitynotfound', 404 );
		}

		$kind = $this->detectKind( $item );
		if ( $kind === null ) {
			throw new RenderException( "Item $id is not an embeddable content item", 'notembeddable', 400 );
		}

		$payload = $this->extractPayload( $item, $kind );
		if ( $payload === [] ) {
			throw new RenderException( "Item $id has no payload for kind '$kind'", 'missingpayload', 400 );
		}

		$negotiated = $this->negotiateLanguage( $payload, $lang, $acceptLanguages );
		$title = $this->labelFor( $item, $negotiated );
		$lastModified = $entityRevision ? $entityRevision->getTimestamp() : null;

		$cacheKey = $this->cache->makeKey(
			'EmbeddableContent', 'embed', $id->getSerialization(),
			(string)( $entityRevision ? $entityRevision->getRevisionId() : 0 ),
			$format, $negotiated
		);

		$html = $this->cache->get( $cacheKey );
		if ( !is_string( $html ) ) {
			$html = $this->renderKind( $kind, $item, $payload, $negotiated );
			$html = $this->attachProvenance( $html, $item, $negotiated );
			// Re-pass through MediaWiki's tag sanitizer (defense in depth).
			$html = \Sanitizer::removeHTMLtags( $html );
			$this->cache->set( $cacheKey, $html, self::CACHE_TTL );
		}

		$languages = [];
		foreach ( $payload as $langCode => $text ) {
			if ( is_string( $langCode ) ) {
				$languages[$langCode] = $text;
			}
		}

		return new RenderResult(
			$kind,
			$title,
			$html,
			$negotiated,
			$languages,
			$cacheKey,
			$lastModified !== null ? (int)wfTimestamp( TS_UNIX, $lastModified ) : null
		);
	}

	/**
	 * Detects the embeddable kind from `instance of` claims.
	 */
	private function detectKind( Item $item ): ?string {
		$instanceOf = $this->config->instanceOfPropertyId();
		$classToKind = array_flip( $this->config->classIds() );

		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof \Wikibase\DataModel\Snak\PropertyValueSnak ) {
				continue;
			}
			if ( $snak->getPropertyId()->getSerialization() !== $instanceOf ) {
				continue;
			}
			$value = $this->unwrapEntityValue( $snak->getDataValue() );
			if ( $value instanceof ItemId && isset( $classToKind[$value->getSerialization()] ) ) {
				return $classToKind[$value->getSerialization()];
			}
		}
		return null;
	}

	/**
	 * Extracts the payload claims for a kind as language => text (quotation)
	 * or [ '' => text ] (code/math).
	 *
	 * @return array<string,string>
	 */
	private function extractPayload( Item $item, string $kind ): array {
		$propertyId = $this->config->payloadPropertyIds()[$kind];
		$result = [];
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof \Wikibase\DataModel\Snak\PropertyValueSnak ) {
				continue;
			}
			if ( $snak->getPropertyId()->getSerialization() !== $propertyId ) {
				continue;
			}
			$value = $this->unwrapEntityValue( $snak->getDataValue() );
			if ( $value instanceof \Wikibase\DataModel\Term\MonolingualTextValue ) {
				$result[$value->getLanguageCode()] = $value->getText();
			} elseif ( $value instanceof \DataValues\StringValue ) {
				$result[''] = $value->getValue();
			}
		}
		return $result;
	}

	/**
	 * Picks the payload language: explicit ?lang=, then Accept-Language order,
	 * then the configured fallback chain, then the first available.
	 *
	 * @param array<string,string> $payload
	 * @param string[] $acceptLanguages
	 */
	private function negotiateLanguage( array $payload, ?string $lang, array $acceptLanguages ): string {
		$available = array_keys( $payload );

		if ( $lang !== null && isset( $payload[$lang] ) ) {
			return $lang;
		}
		foreach ( $acceptLanguages as $candidate ) {
			if ( isset( $payload[$candidate] ) ) {
				return $candidate;
			}
		}
		foreach ( $this->config->fallbackLanguages() as $candidate ) {
			if ( isset( $payload[$candidate] ) ) {
				return $candidate;
			}
		}
		return $available[0];
	}

	private function renderKind( string $kind, Item $item, array $payload, string $lang ): string {
		switch ( $kind ) {
			case 'quotation':
				return $this->quoteRenderer->render( $payload[$lang], $lang );
			case 'code':
				$lexer = $this->languageLexer( $item );
				return $this->codeRenderer->render( $payload[''] ?? '', $lexer );
			case 'math':
				return $this->mathRenderer->render( $payload[''] ?? '' );
		}
		throw new RenderException( "Unknown kind '$kind'", 'notembeddable', 400 );
	}

	private function languageLexer( Item $item ): string {
		$programmingLanguage = $this->config->programmingLanguagePropertyId();
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof \Wikibase\DataModel\Snak\PropertyValueSnak ) {
				continue;
			}
			if ( $snak->getPropertyId()->getSerialization() !== $programmingLanguage ) {
				continue;
			}
			$value = $this->unwrapEntityValue( $snak->getDataValue() );
			if ( $value instanceof ItemId ) {
				$lexer = $this->config->lexerForItemId( $value->getSerialization() );
				if ( $lexer !== null ) {
					return $lexer;
				}
			}
		}
		return 'text';
	}

	/**
	 * Appends the provenance footer (attributed to / source / date / URL).
	 */
	private function attachProvenance( string $html, Item $item, string $lang ): string {
		$provenance = $this->config->provenancePropertyIds();
		$parts = [];
		$authors = [];
		$sources = [];
		$url = null;
		$date = null;

		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof \Wikibase\DataModel\Snak\PropertyValueSnak ) {
				continue;
			}
			$propId = $snak->getPropertyId()->getSerialization();
			$value = $this->unwrapEntityValue( $snak->getDataValue() );

			if ( isset( $provenance['attributedTo'] ) && $propId === $provenance['attributedTo'] && $value instanceof ItemId ) {
				$target = $this->entityLookup->getEntity( $value );
				if ( $target instanceof Item ) {
					$authors[] = $this->sanitizer->escapeText( $this->labelFor( $target, $lang ) );
				}
			} elseif ( isset( $provenance['source'] ) && $propId === $provenance['source'] && $value instanceof ItemId ) {
				$target = $this->entityLookup->getEntity( $value );
				if ( $target instanceof Item ) {
					$sources[] = $this->sanitizer->escapeText( $this->labelFor( $target, $lang ) );
				}
			} elseif ( isset( $provenance['sourceUrl'] ) && $propId === $provenance['sourceUrl'] && $value instanceof \DataValues\StringValue ) {
				$url = $value->getValue();
			} elseif ( isset( $provenance['date'] ) && $propId === $provenance['date'] && $value instanceof \DataValues\TimeValue ) {
				$date = $value->getTime();
			}
		}

		if ( $authors !== [] ) {
			$parts[] = '<cite class="wb-embed-author">' . implode( ', ', $authors ) . '</cite>';
		}
		if ( $sources !== [] ) {
			$parts[] = '<span class="wb-embed-source">' . implode( ', ', $sources ) . '</span>';
		}
		if ( $date !== null ) {
			$parts[] = '<time class="wb-embed-date" datetime="' . $this->sanitizer->escapeAttribute( substr( $date, 0, 10 ) ) . '">'
				. $this->sanitizer->escapeText( trim( $date, '+' ) ) . '</time>';
		}
		$safeUrl = $url !== null ? $this->sanitizer->validateUrl( $url ) : null;
		if ( $safeUrl !== null ) {
			$parts[] = '<a class="wb-embed-sourceurl" href="' . $this->sanitizer->escapeUrl( $safeUrl ) . '">'
				. $this->sanitizer->escapeText( $safeUrl ) . '</a>';
		}

		if ( $parts === [] ) {
			return $html;
		}
		return $html . '<footer class="wb-embed-footer">' . implode( ' · ', $parts ) . '</footer>';
	}

	/**
	 * DataModel 9 wraps entity-id values in EntityIdValue; unwrap to ItemId.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function unwrapEntityValue( $value ) {
		return $value instanceof EntityIdValue ? $value->getEntityId() : $value;
	}

	/**
	 * Resolves an item's label with the fallback chain: exact language, then
	 * the configured fallback languages, then the first available.
	 */
	private function labelFor( Item $item, string $lang ): string {
		$label = $item->getFingerprint()->getLabel( $lang );
		if ( $label !== null ) {
			return $label->getText();
		}
		foreach ( $this->config->fallbackLanguages() as $fallback ) {
			$label = $item->getFingerprint()->getLabel( $fallback );
			if ( $label !== null ) {
				return $label->getText();
			}
		}
		$labels = $item->getFingerprint()->getLabels()->toTextArray();
		return $labels === [] ? $item->getId()->getSerialization() : reset( $labels );
	}
}
