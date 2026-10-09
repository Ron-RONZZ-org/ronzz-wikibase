<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Context\IContextSource;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\BagOStuff;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikibase\Repo\WikibaseRepo;

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

	/** Tags removed by the sanitizer re-pass (script/iframe/form/etc.). */
	public const BARRED_TAGS = [
		'script', 'iframe', 'object', 'embed', 'form', 'input', 'button',
		'textarea', 'select', 'style', 'link', 'meta', 'svg', 'math', 'video',
		'audio', 'canvas', 'applet', 'frame', 'frameset', 'noscript', 'noembed',
		'template',
	];

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

	/** @var RichTextRenderer */
	private $richText;

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
		$this->richText = new RichTextRenderer();
	}

	/**
	 * @param string[] $acceptLanguages preferred languages in order (from Accept-Language)
	 * @param bool $preview the in-wiki preview mode (Item page + Add* success
	 *  popup): a quotation renders its ORIGINAL + the reader-language
	 *  translation (when present) + the `-author, ''source''` attribution
	 *  line, instead of the single negotiated language of the framed embed.
	 *  Math/code fragments are unchanged.
	 *
	 * @throws RenderException
	 */
	public function render(
		ItemId $id,
		string $format,
		?string $lang = null,
		?int $revId = null,
		array $acceptLanguages = [],
		bool $preview = false
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
			$payloadProperty = $this->config->payloadPropertyIds()[$kind === 'law' ? 'quotation' : $kind] ?? '?';
			$props = [];
			$values = [];
			foreach ( $item->getStatements() as $s ) {
				$props[] = $s->getMainSnak()->getPropertyId()->getSerialization();
				$snak = $s->getMainSnak();
				if ( $snak instanceof \Wikibase\DataModel\Snak\PropertyValueSnak
					&& $snak->getPropertyId()->getSerialization() === $payloadProperty
				) {
					$dv = $snak->getDataValue();
					$values[] = get_class( $dv )
						. ( $dv instanceof \DataValues\MonolingualTextValue ? ':' . $dv->getLanguageCode() : '' );
				}
			}
			throw new RenderException(
				"Item $id has no payload for kind '$kind' (payload property $payloadProperty, "
				. count( $item->getStatements() ) . " statements: " . implode( ',', $props )
				. '; P2 values: ' . implode( ',', $values ) . ')',
				'missingpayload',
				400
			);
		}

		// `lang=all` renders every available payload language (multi-language
		// embed for quotations); otherwise negotiate a single language. The
		// preview mode never multi-renders (it shows the original + one
		// reader-language translation).
		$multi = ( !$preview && $lang === 'all' );
		if ( $multi ) {
			$negotiated = 'all';
		} else {
			$negotiated = $this->negotiateLanguage( $item, $payload, $lang, $acceptLanguages );
		}
		$title = $this->labelFor( $item, $negotiated );
		$lastModified = $entityRevision ? $entityRevision->getTimestamp() : null;

		// The cache key must be the REVISION the fragment was built from. A
		// caller that passes no `rev` (action=embed, oEmbed, the Item-page
		// preview) reads the item at its LATEST revision; keying that render
		// at revision 0 made it immutable for the 30-day TTL, so an update
		// was never reflected (the Q2039 stale math snippet preview).
		// Resolve the latest revision id and key on it — a new revision then
		// naturally misses the old entry. Wikibase's getLatestRevisionId()
		// returns a LatestRevisionIdResult monad (a redirect carries the
		// target's revision id), so map it to the int.
		$revisionId = $entityRevision ? $entityRevision->getRevisionId() : 0;
		if ( $revisionId <= 0 ) {
			$revisionId = \EmbeddableContent\Spec\LatestRevision::id( $this->revisionLookup, $id );
		}

		$cacheKey = $this->cache->makeKey(
			'EmbeddableContent', $preview ? 'preview' : 'embed', $id->getSerialization(),
			(string)$revisionId,
			$format, $negotiated
		);

		$html = $this->cache->get( $cacheKey );
		$modules = [];
		$moduleStyles = [];
		if ( is_array( $html ) && isset( $html['html'] ) ) {
			$cached = $html;
			$html = (string)$cached['html'];
			$modules = is_array( $cached['modules'] ?? null ) ? $cached['modules'] : [];
			$moduleStyles = is_array( $cached['moduleStyles'] ?? null ) ? $cached['moduleStyles'] : [];
		} elseif ( is_string( $html ) ) {
			// Legacy cache entry (pre-rich-content): fragment HTML only.
		} else {
			// Rich-content fragments (parsed wikitext) are substituted AFTER
			// the sanitizer re-pass: MediaWiki's parser is their sanitizer,
			// and the re-pass whitelist would escape the media markup
			// ([[File:…]] → <figure>/<img>). A per-render token carries the
			// parsed HTML through the sanitizer untouched.
			$richParts = [];
			// Legal provisions share the quotation preview (original + one
			// reader-language translation) but carry NO attribution line.
			$isQuotationPreview = $preview && ( $kind === 'quotation' || $kind === 'law' );
			if ( $isQuotationPreview ) {
				$html = $this->renderQuotationPreview(
					$item,
					$payload,
					$lang,
					$richParts,
					$kind !== 'law'
				);
			} elseif ( $multi ) {
				$fragments = [];
				foreach ( $payload as $code => $text ) {
					$fragments[] = $this->renderKind( $kind, $item, [ $code => $text ], (string)$code, $richParts );
				}
				$html = implode( "\n", $fragments );
			} else {
				$html = $this->renderKind( $kind, $item, $payload, $negotiated, $richParts );
			}
			if ( !$isQuotationPreview ) {
				$html = $this->attachProvenance( $html, $item, $negotiated );
			}
			// Re-pass through MediaWiki's tag sanitizer (defense in depth,
			// issue #6 §1.7). MW 1.46 removed removeHTMLtags; removeSomeTags
			// with an explicit barred-tag list preserves our controlled
			// fragment markup — footer and a must be whitelisted or they
			// get escaped as text (observed on quote/code embeds).
			$html = \Sanitizer::removeSomeTags( $html, [
				'removeTags' => self::BARRED_TAGS,
				'extraTags' => [ 'footer', 'a' ],
			] );
			foreach ( $richParts as $token => $rich ) {
				$html = str_replace( $token, $rich->getHtml(), $html );
				$modules = array_merge( $modules, $rich->getModules() );
				$moduleStyles = array_merge( $moduleStyles, $rich->getModuleStyles() );
			}
			$modules = array_values( array_unique( $modules ) );
			$moduleStyles = array_values( array_unique( $moduleStyles ) );
			$this->cache->set( $cacheKey, [
				'html' => $html,
				'modules' => $modules,
				'moduleStyles' => $moduleStyles,
			], self::CACHE_TTL );
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
			$lastModified !== null ? (int)wfTimestamp( TS_UNIX, $lastModified ) : null,
			$modules,
			$moduleStyles
		);
	}

	/**
	 * Detects the embeddable kind from `instance of` claims.
	 */
	private function detectKind( Item $item ): ?string {
		$instanceOf = $this->config->instanceOfPropertyId();
		$classToKind = array_flip( $this->config->classIds() );
		// A legal provision (the AddSource `law` class) renders through the
		// quotation monolingual path — the same class `{{#content:}}` accepts.
		$lawClass = $this->config->lawClass();

		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof \Wikibase\DataModel\Snak\PropertyValueSnak ) {
				continue;
			}
			if ( $snak->getPropertyId()->getSerialization() !== $instanceOf ) {
				continue;
			}
			$value = $this->unwrapEntityValue( $snak->getDataValue() );
			if ( !$value instanceof ItemId ) {
				continue;
			}
			$classId = $value->getSerialization();
			if ( $lawClass !== null && $classId === $lawClass ) {
				return 'law';
			}
			if ( isset( $classToKind[$classId] ) ) {
				return $classToKind[$classId];
			}
		}
		return null;
	}

	/**
	 * Extracts the payload claims for a kind as language => text (quotation)
	 * or [ '' => text ] (code/math). For quotations the added `translation`
	 * claims join as additional available languages (the base wins on a
	 * language collision), so `lang=fr`/`lang=eo`/`lang=all` keep working
	 * after the originals move to the dedicated translation property.
	 *
	 * @return array<string,string>
	 */
	private function extractPayload( Item $item, string $kind ): array {
		// A legal provision reuses the quotation payload (`content text`)
		// property and its added `translation` claims.
		$payloadKind = $kind === 'law' ? 'quotation' : $kind;
		$result = $this->collectMonolingual( $item, $this->config->payloadPropertyIds()[$payloadKind] );
		if ( $kind === 'quotation' || $kind === 'law' ) {
			$translationPropertyId = $this->config->translationPropertyId();
			if ( $translationPropertyId !== null ) {
				foreach ( $this->collectMonolingual( $item, $translationPropertyId ) as $code => $text ) {
					if ( !array_key_exists( $code, $result ) ) {
						$result[$code] = $text;
					}
				}
			}
		}
		return $result;
	}

	/**
	 * The language => decoded-text map of one monolingualtext/string
	 * property's claims.
	 *
	 * @return array<string,string>
	 */
	private function collectMonolingual( Item $item, string $propertyId ): array {
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
			if ( $value instanceof \DataValues\MonolingualTextValue ) {
				$result[$value->getLanguageCode()] = PayloadCodec::decode( $value->getText() );
			} elseif ( $value instanceof \DataValues\StringValue ) {
				$result[''] = PayloadCodec::decode( $value->getValue() );
			}
		}
		return $result;
	}

	/**
	 * Picks the display language: explicit ?lang=, then Accept-Language order,
	 * then the configured fallback chain, then the first language with a
	 * label. For language-carrying payloads (quotation) the choice must be
	 * backed by a payload claim; code/math payloads have no language, so the
	 * choice is display-only.
	 *
	 * @param array<string,string> $payload
	 * @param string[] $acceptLanguages
	 */
	private function negotiateLanguage( Item $item, array $payload, ?string $lang, array $acceptLanguages ): string {
		$labelLangs = array_keys( $item->getFingerprint()->getLabels()->toTextArray() );
		$chain = array_values( array_unique( array_merge(
			$lang !== null ? [ $lang ] : [],
			$acceptLanguages,
			$this->config->fallbackLanguages(),
			$labelLangs
		) ) );

		$payloadLangs = array_keys( array_filter( $payload, static function ( $text, $code ) {
			return $code !== '';
		}, ARRAY_FILTER_USE_BOTH ) );

		foreach ( $chain as $candidate ) {
			if ( $payloadLangs === [] ) {
				return $candidate; // code/math: language is display-only
			}
			if ( isset( $payload[$candidate] ) ) {
				return $candidate;
			}
		}
		return $payloadLangs === [] ? '' : reset( $payloadLangs );
	}

	private function renderKind( string $kind, Item $item, array $payload, string $lang, array &$richParts ): string {
		switch ( $kind ) {
			case 'quotation':
			case 'law':
				// Rich content: the payload is full wikitext ([[File:…]],
				// links, emphasis, $…$) parsed by MediaWiki's own sanitizer.
				// A legal provision reuses this monolingual quotation path.
				return $this->quoteRenderer->wrapHtml(
					$this->richFragment( $payload[$lang], $item, $richParts ),
					$lang
				);
			case 'code':
				$lexer = $this->languageLexer( $item );
				return $this->codeRenderer->render( $payload[''] ?? '', $lexer );
			case 'math':
				$html = $this->mathRenderer->render( $payload[''] ?? '' );
				$note = $this->noteFor( $item );
				if ( $note !== '' ) {
					// The note is plain rich wikitext below the expression —
					// it deliberately carries NO `.wb-embed` chrome (the blue
					// left border read as a jarring stray line on the Item-
					// page preview).
					$html .= '<div class="wb-embed-note">'
						. $this->richFragment( $note, $item, $richParts )
						. '</div>';
				}
				return $html;
		}
		throw new RenderException( "Unknown kind '$kind'", 'notembeddable', 400 );
	}

	/**
	 * The quotation preview (Item page + Add* success popup): the ORIGINAL
	 * payload followed — when the reader's language has a translation — by
	 * that translation block, then the `-author, ''source''` attribution
	 * line. Distinct from the framed single-language embed (Special:Embed /
	 * third-party iframes) and from `lang=all`: the reader sees the source
	 * text AND its translation side by side.
	 *
	 * @param array<string,string> $payload language => decoded text, base first
	 * @param array<string,RichTextResult> &$richParts
	 * @param bool $withAttribution append the `-author, ''source''` line (a
	 *  legal provision carries none — its `part of` parent, not a person, is
	 *  the context)
	 */
	private function renderQuotationPreview(
		Item $item,
		array $payload,
		?string $targetLang,
		array &$richParts,
		bool $withAttribution = true
	): string {
		if ( $payload === [] ) {
			return '';
		}
		$baseLang = array_key_first( $payload );
		$baseLang = is_string( $baseLang ) ? $baseLang : '';
		$wikitext = (string)$payload[$baseLang];

		// The reader-language translation, when it exists and differs from
		// the original's language.
		if ( $targetLang !== null && $targetLang !== '' && $targetLang !== $baseLang
			&& isset( $payload[$targetLang] )
		) {
			$header = wfMessage( 'embeddablecontent-content-translation-header', $targetLang )->text();
			$wikitext .= "\n\n'''" . $header . "'''\n\n" . $payload[$targetLang];
		}

		if ( $withAttribution ) {
			$attribution = $this->quotationAttributionWikitext( $item );
			if ( $attribution !== '' ) {
				$wikitext .= "\n\n" . $attribution;
			}
		}

		return $this->quoteRenderer->wrapHtml(
			$this->richFragment( $wikitext, $item, $richParts ),
			$baseLang
		);
	}

	/**
	 * The `-author, ''source''` attribution wikitext of a quotation (the
	 * same assembly as `{{#content:}}`), or '' when the item carries neither.
	 */
	private function quotationAttributionWikitext( Item $item ): string {
		$provenance = $this->config->provenancePropertyIds();
		$authors = ProvenanceWikitext::authorLabels(
			$item,
			$provenance['attributedTo'] ?? null,
			$this->entityLookup
		);
		$sources = ProvenanceWikitext::sourceWikitexts(
			$item,
			$provenance['source'] ?? null,
			$this->entityLookup
		);
		return ContentWikitext::quotationAttribution( $authors, $sources );
	}

	/**
	 * Parses a rich wikitext fragment and stores its HTML under a unique
	 * token; the token survives the sanitizer re-pass and is substituted
	 * afterwards (see render()).
	 *
	 * @param array<string,RichTextResult> &$richParts
	 */
	private function richFragment( string $wikitext, Item $item, array &$richParts ): string {
		$result = $this->richText->render( $wikitext, $this->entityTitle( $item ) );
		$token = self::richToken();
		$richParts[$token] = $result;
		return $token;
	}

	/** A per-render token unlikely to collide with user content. */
	private static function richToken(): string {
		return 'WBRICHTEXT' . bin2hex( random_bytes( 8 ) ) . 'END';
	}

	/** The item's page title, used as the parse context for rich fragments. */
	private function entityTitle( Item $item ): ?Title {
		$id = $item->getId();
		if ( $id === null ) {
			return null;
		}
		try {
			$services = \MediaWiki\MediaWikiServices::getInstance();
			return WikibaseRepo::getEntityTitleStoreLookup( $services )->getTitleForId( $id );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The item's accompanying note (math items): the decoded wikitext of the
	 * `note` property, or '' when the item carries none / the instance has no
	 * note vocabulary.
	 */
	private function noteFor( Item $item ): string {
		$noteProperty = $this->config->notePropertyId();
		if ( $noteProperty === null ) {
			return '';
		}
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof \Wikibase\DataModel\Snak\PropertyValueSnak
				|| $snak->getPropertyId()->getSerialization() !== $noteProperty
			) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof \DataValues\StringValue ) {
				return PayloadCodec::decode( $value->getValue() );
			}
		}
		return '';
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
		$label = $this->safeGetLabel( $item, $lang );
		if ( $label !== null ) {
			return $label->getText();
		}
		foreach ( $this->config->fallbackLanguages() as $fallback ) {
			$label = $this->safeGetLabel( $item, $fallback );
			if ( $label !== null ) {
				return $label->getText();
			}
		}
		$labels = $item->getFingerprint()->getLabels()->toTextArray();
		return $labels === [] ? $item->getId()->getSerialization() : reset( $labels );
	}

	/**
	 * TermList::getByLanguage throws OutOfBoundsException for languages the
	 * item does not carry (e.g. the synthetic 'all' marker) — the fallback
	 * chain must not fatal on those.
	 */
	private function safeGetLabel( Item $item, string $lang ): ?\Wikibase\DataModel\Term\Term {
		try {
			return $item->getFingerprint()->getLabel( $lang );
		} catch ( \OutOfBoundsException $e ) {
			return null;
		}
	}
}
