<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikimedia\ObjectCache\BagOStuff;

/**
 * Shared citation service (issue #24, ADR `docs/decisions/cite-by-qid.md`
 * §Architecture): entity id → item → CSL-JSON → formatted string, with the
 * revId-keyed BagOStuff cache. Single rendering path used by both the
 * `action=citation` API module and the `{{#cite}}` / `{{#citations}}`
 * parser functions — a refactor of `ApiCitation::execute()`.
 *
 * - The cache is keyed on entity revision id, so any edit to the cited item
 *   invalidates the entry; `json` output is never cached (verbatim from the
 *   original API implementation).
 * - citeproc-php HTML output (apa / vancouver) is passed through the
 *   allowlist CitationSanitizer before it reaches any caller — statement
 *   values must never survive rendering as markup. The `json` / `bibtex` /
 *   `ris` html outputs are already htmlspecialchars-wrapped and are not
 *   sanitized (their `<pre>` wrappers are not part of the allowlist).
 * - `{{#cite}}` needs the cited entity's *source item id* alongside the
 *   rendered text (for the `{{#citations}}` bibliography); both are derived
 *   from the single entity load performed per render.
 *
 * @license GPL-2.0-or-later
 */
class CitationEngine {

	private const CACHE_TTL = 300;

	/** @var StatementToCslConverter */
	private $converter;

	/** @var CitationFormatter */
	private $formatter;

	/** @var EntityLookup */
	private $entityLookup;

	/** @var EntityRevisionLookup */
	private $revisionLookup;

	/** @var BagOStuff */
	private $cache;

	/** @var CitationSanitizer */
	private $sanitizer;

	/** @var EntityIdParser */
	private $idParser;

	public function __construct(
		StatementToCslConverter $converter,
		CitationFormatter $formatter,
		EntityLookup $entityLookup,
		EntityRevisionLookup $revisionLookup,
		BagOStuff $cache,
		CitationSanitizer $sanitizer,
		EntityIdParser $idParser
	) {
		$this->converter = $converter;
		$this->formatter = $formatter;
		$this->entityLookup = $entityLookup;
		$this->revisionLookup = $revisionLookup;
		$this->cache = $cache;
		$this->sanitizer = $sanitizer;
		$this->idParser = $idParser;
	}

	/**
	 * Renders a citation for an entity id in the given style and format.
	 * Missing entity fields are omitted, never fatal; the revId-keyed cache
	 * serves repeated renders within the TTL. The `json` style returns the
	 * JSON string (formatted, never cached).
	 *
	 * @throws CitationException on invalid id / unknown entity / bad style
	 */
	public function render( string $entityId, string $style, string $format = 'text' ): string {
		return $this->renderWithSourceId( $entityId, $style, $format )[0];
	}

	/**
	 * Multi-entity render (issue #25 v2: `{{#cite:Q42|Q7}}` — a CSL
	 * bibliography of several items in one output, e.g. one footnote).
	 * Each entity renders through the single-entity path (per-entity cache),
	 * the outputs are joined. The `json` style returns a JSON array of the
	 * CSL structures (never cached, like single json).
	 *
	 * @param string[] $entityIds
	 * @return array{0:string,1:string[]} [joined text, deduped source ids]
	 *
	 * @throws CitationException on the first invalid id / unknown entity /
	 *  bad style
	 */
	public function renderList( array $entityIds, string $style, string $format = 'text' ): array {
		$sourceIds = [];
		if ( $style === 'json' ) {
			$csls = [];
			foreach ( $entityIds as $entityId ) {
				$item = $this->loadItem( $entityId );
				$csls[] = $this->converter->toCslJson( $item );
				$sourceId = $this->sourceItemIdOf( $item );
				if ( $sourceId !== null ) {
					$sourceIds[] = $sourceId->getSerialization();
				}
			}
			return [ (string)json_encode( $csls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), array_values( array_unique( $sourceIds ) ) ];
		}

		$chunks = [];
		foreach ( $entityIds as $entityId ) {
			[ $text, $sourceId ] = $this->renderWithSourceId( $entityId, $style, $format );
			$chunks[] = $text;
			if ( $sourceId !== null ) {
				$sourceIds[] = $sourceId->getSerialization();
			}
		}
		return [ implode( "\n", $chunks ), array_values( array_unique( $sourceIds ) ) ];
	}

	/**
	 * Resolves the *source item id* of a cited entity id string (issue #25
	 * v2: explicit `{{#citations:Q42|Q7}}` and embed auto-collect need the
	 * source of an arbitrary entity, not just the one just rendered).
	 *
	 * @throws CitationException on invalid id / unknown entity
	 */
	public function sourceIdFor( string $entityId ): ?ItemId {
		return $this->sourceItemIdOf( $this->loadItem( $entityId ) );
	}

	/**
	 * The raw CSL-JSON structure of an entity — the API's `style=json`
	 * output shape (nested in the API result, never cached: verbatim
	 * contract of the original implementation).
	 *
	 * @return array<string,mixed>
	 *
	 * @throws CitationException on invalid id / unknown entity
	 */
	public function renderToCsl( string $entityId ): array {
		return $this->converter->toCslJson( $this->loadItem( $entityId ) );
	}

	/**
	 * Renders a citation AND resolves the source item id of the cited entity
	 * (null when the item is neither a source class nor has a `source`
	 * statement). Both come from the single entity load of this call.
	 *
	 * @return array{0:string,1:?ItemId} [citation text, source item id]
	 *
	 * @throws CitationException on invalid id / unknown entity / bad style
	 */
	public function renderWithSourceId( string $entityId, string $style, string $format = 'text' ): array {
		if ( !in_array( $style, CitationFormatter::STYLES, true ) ) {
			throw new CitationException( "Unsupported citation style: '$style'" );
		}
		$item = $this->loadItem( $entityId );
		$sourceId = $this->sourceItemIdOf( $item );
		$revision = $this->revisionLookup->getEntityRevision( $item->getId() );
		$revId = $revision !== null ? $revision->getRevisionId() : 0;

		$cacheKey = $this->cache->makeKey(
			'WikibaseCitation', 'citation', $item->getId()->getSerialization(), (string)$revId, $style, $format
		);
		$cached = $this->cache->get( $cacheKey );
		if ( is_string( $cached ) ) {
			return [ $cached, $sourceId ];
		}

		$csl = $this->converter->toCslJson( $item );
		if ( $style === 'json' ) {
			// json output is the JSON string, and it is never cached.
			$text = $this->formatter->format( $csl, 'json', $format );
		} else {
			$text = $this->formatter->format( $csl, $style, $format );
			$this->cache->set( $cacheKey, $text, self::CACHE_TTL );
		}

		if ( $format === 'html' && ( $style === 'apa' || $style === 'vancouver' ) ) {
			$text = $this->sanitizer->sanitizeHtml( $text );
		}
		return [ $text, $sourceId ];
	}

	/**
	 * Loads the item behind an entity id string.
	 *
	 * @throws InvalidCitationIdException when the id is not a valid item id
	 * @throws CitationEntityNotFoundException when the item does not exist
	 */
	private function loadItem( string $entityId ): Item {
		$id = $this->parseItemId( $entityId );
		if ( $id === null ) {
			throw new InvalidCitationIdException( "Invalid entity id: '$entityId'", $entityId );
		}
		$revision = $this->revisionLookup->getEntityRevision( $id );
		$item = $revision !== null ? $revision->getEntity() : $this->entityLookup->getEntity( $id );
		if ( !$item instanceof Item ) {
			throw new CitationEntityNotFoundException(
				"Entity not found or not an item: {$id->getSerialization()}",
				$id->getSerialization()
			);
		}
		return $item;
	}

	/**
	 * Resolves the *source item id* of a cited item, for the
	 * `{{#citations}}` bibliography accumulation (issue #24): a source-class
	 * item is its own source; otherwise the `source` statement target, or
	 * null when the item has no source.
	 */
	public function sourceItemIdOf( Item $item ): ?ItemId {
		$source = $this->converter->sourceItemOf( $item );
		if ( $source !== null ) {
			return $source->getId();
		}
		if ( $this->converter->isSourceClass( $item ) ) {
			return $item->getId();
		}
		return null;
	}

	private function parseItemId( string $input ): ?ItemId {
		try {
			$entityId = $this->idParser->parse( $input );
			return $entityId instanceof ItemId ? $entityId : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Public wrapper so surfaces can normalize an entity id for their own
	 * response payloads (the API echoes the canonical serialization).
	 */
	public function normalizeItemId( string $input ): ?ItemId {
		return $this->parseItemId( $input );
	}
}
