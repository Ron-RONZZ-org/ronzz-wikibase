<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use DataValues\MonolingualTextValue;
use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\Content\MathRenderer;
use EmbeddableContent\Spec\AdditionalStatements;
use EmbeddableContent\Content\PayloadCodec;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\PropertyIdFactory;
use EmbeddableContent\Spec\TranslationList;
use Wikibase\DataModel\DataValue;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\StatementList;

/**
 * The entity-mode special-content pipeline (quotation / math / code-snippet)
 * — the logic the action=addspecialcontent API module (and through it the
 * MCP embeddable-add-special-content tool) runs. Pure PHP, unit-testable.
 * Field exposure is owned by SpecialContentFieldMap; the payload escaping
 * and math delimiter stripping mirror the Special:AddQuotation / AddMath /
 * AddCodeSnippet forms, and content items create no classic page.
 *
 * @license GPL-2.0-or-later
 */
final class SpecialContentFlowService {

	/** Browser-form-only record keys the API contract does not expose. */
	private const INTERNAL_FIELDS = [ 'additionalStatements' ];

	public const ERROR_TITLE_REQUIRED = 'label is required when creating a content item.';
	public const ERROR_PAYLOAD_REQUIRED = 'content is required when creating a content item.';
	public const ERROR_ATTRIBUTION_REQUIRED = 'attributedTo or source is required for quotations.';

	/**
	 * @param \Closure(string $messageKey, string[] $params): string $message
	 */
	public function __construct(
		private readonly EmbeddableContentConfig $config,
		private readonly \Closure $message
	) {
	}

	/**
	 * Validates and normalizes a record. Returns an error string, or null
	 * after mutating $record (escaped payload, stripped math delimiters).
	 *
	 * @param array<string,mixed> $record
	 */
	public function prepare( string $kind, array &$record, bool $creating ): ?string {
		if ( !in_array( $kind, SpecialContentFieldMap::KINDS, true ) ) {
			return "kind {$kind} is not one of " . implode( ', ', SpecialContentFieldMap::KINDS ) . '.';
		}
		$unknown = array_diff( array_keys( $record ), array_merge( SpecialContentFieldMap::ALL_FIELDS, self::INTERNAL_FIELDS ) );
		if ( $unknown !== [] ) {
			return 'unknown field(s): ' . implode( ', ', $unknown ) . '.';
		}
		$provided = array_filter( $record, static fn ( $v ) => $v !== null && $v !== '' );
		$disallowed = array_diff( array_keys( $provided ), array_merge( SpecialContentFieldMap::fieldsForKind( $kind ), self::INTERNAL_FIELDS ) );
		if ( $disallowed !== [] ) {
			return "kind {$kind} does not accept the field(s) " . implode( ', ', $disallowed )
				. '. Its fields are ' . implode( ', ', SpecialContentFieldMap::fieldsForKind( $kind ) ) . '.';
		}

		if ( $creating ) {
			foreach ( SpecialContentFieldMap::requiredOnCreate( $kind ) as $required ) {
				if ( !isset( $provided[$required] ) ) {
					if ( $required === 'label' ) {
						return self::ERROR_TITLE_REQUIRED;
					}
					if ( $required === 'content' ) {
						return self::ERROR_PAYLOAD_REQUIRED;
					}
					return self::ERROR_ATTRIBUTION_REQUIRED;
				}
			}
			foreach ( SpecialContentFieldMap::requiredAnyOf( $kind ) as $group ) {
				$present = false;
				foreach ( $group as $field ) {
					if ( isset( $provided[$field] ) ) {
						$present = true;
						break;
					}
				}
				if ( !$present ) {
					return self::ERROR_ATTRIBUTION_REQUIRED;
				}
			}
		}

		$payload = trim( (string)( $record['content'] ?? '' ) );
		if ( $kind === 'math' ) {
			$payload = MathRenderer::stripDelimiters( $payload );
		}
		if ( $payload !== '' ) {
			$record['content'] = PayloadCodec::escape( $payload );
		}

		// The math accompanying notes are rich wikitext (links, media, $…$):
		// stored escaped-at-rest like every multi-line payload, decoded at
		// render. `note` renders below the expression, `preNote` above it.
		$note = trim( (string)( $record['note'] ?? '' ) );
		if ( $note !== '' ) {
			$record['note'] = PayloadCodec::escape( $note );
		}
		$preNote = trim( (string)( $record['preNote'] ?? '' ) );
		if ( $preNote !== '' ) {
			$record['preNote'] = PayloadCodec::escape( $preNote );
		}

		$language = (string)( $record['language'] ?? 'en' );
		if ( $kind === 'quotation' && !preg_match( '/^[a-z]{2,8}(?:-[a-z0-9]{2,8})*$/i', $language ) ) {
			return "language \"{$language}\" is not a valid language code.";
		}

		// Quotation translations (the AddQuotation "Add translation" field):
		// a list of {language, content} rows stored as monolingual `translation`
		// claims. An empty-but-present list clears the translations on update;
		// an absent key leaves them untouched (no-clobber).
		if ( array_key_exists( 'translations', $record ) ) {
			$baseLanguage = isset( $record['language'] ) ? (string)$record['language'] : null;
			$normalized = TranslationList::normalize( $record['translations'], $baseLanguage );
			if ( is_string( $normalized ) ) {
				return $normalized;
			}
			$record['translations'] = $normalized;
		}

		$labelLanguage = (string)( $record['labelLanguage'] ?? 'en' );
		if ( !preg_match( '/^[a-z]{2,8}(?:-[a-z0-9]{2,8})*$/i', $labelLanguage ) ) {
			return "labelLanguage \"{$labelLanguage}\" is not a valid language code.";
		}

		foreach ( [ 'programmingLanguage', 'source' ] as $entityField ) {
			$value = trim( (string)( $record[$entityField] ?? '' ) );
			if ( $value !== '' && preg_match( '/^[QqPp][1-9]\d*$/', $value ) !== 1 ) {
				return "{$entityField} \"{$value}\" is not an item ID.";
			}
		}
		// `attributedTo` is a list (the author panel confirms several names;
		// the API accepts a comma-separated string too).
		foreach ( $this->splitItemIds( (string)( $record['attributedTo'] ?? '' ) ) as $id ) {
			// Authors are ITEMS (a P-id would reach ItemId() and throw).
			if ( preg_match( '/^Q[1-9]\d*$/i', $id ) !== 1 ) {
				return "attributedTo contains \"{$id}\", which is not an item ID.";
			}
		}
		foreach ( [ 'describes', 'implementationOf' ] as $listField ) {
			foreach ( $this->splitItemIds( (string)( $record[$listField] ?? '' ) ) as $id ) {
				if ( preg_match( '/^Q[1-9]\d*$/', $id ) !== 1 ) {
					return "{$listField} contains \"{$id}\", which is not an item ID.";
				}
			}
		}

		$sourceUrl = trim( (string)( $record['sourceUrl'] ?? '' ) );
		if ( $sourceUrl !== '' && !$this->isHttpUrl( $sourceUrl ) ) {
			return "sourceUrl \"{$sourceUrl}\" is not an http(s) URL.";
		}

		$date = trim( (string)( $record['date'] ?? '' ) );
		if ( $date !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) !== 1 ) {
			return "date \"{$date}\" is not YYYY-MM-DD.";
		}

		if ( array_key_exists( 'additionalStatements', $record ) ) {
			if ( !is_array( $record['additionalStatements'] ) ) {
				return 'additionalStatements must be a list of {property, value} rows.';
			}
			$managed = array_keys( $this->statementSpecs( $kind, $record ) );
			$managed[] = $this->config->instanceOfPropertyId();
			$built = AdditionalStatements::dataValues(
				$record['additionalStatements'],
				array_values( array_unique( $managed ) )
			);
			if ( $built['error'] !== null ) {
				return $built['error'];
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $record
	 * @return array<string, DataValue|DataValue[]>
	 */
	public function statementSpecs( string $kind, array $record ): array {
		$specs = [];
		$classId = $this->config->classIds()[SpecialContentFieldMap::formKey( $kind )] ?? null;
		if ( $classId !== null ) {
			$specs[$this->config->instanceOfPropertyId()] = new EntityIdValue( new ItemId( $classId ) );
		}

		$payloadPropertyId = $this->config->payloadPropertyIds()[SpecialContentFieldMap::formKey( $kind )] ?? null;
		if ( $payloadPropertyId !== null && isset( $record['content'] ) && $record['content'] !== '' ) {
			if ( $kind === 'quotation' ) {
				$specs[$payloadPropertyId] = new MonolingualTextValue(
					(string)( $record['language'] ?? 'en' ),
					(string)$record['content']
				);
			} else {
				$specs[$payloadPropertyId] = new StringValue( (string)$record['content'] );
			}
		}

		if ( $kind === 'code-snippet' && !empty( $record['programmingLanguage'] ) ) {
			$specs[$this->config->programmingLanguagePropertyId()] = new EntityIdValue(
				new ItemId( strtoupper( (string)$record['programmingLanguage'] ) )
			);
		}

		// Quotation translations: one monolingual `translation` claim per
		// language. The key's PRESENCE (even an empty list) manages the
		// property — a partial update that omits it keeps the existing
		// translations (no-clobber), while a present-but-empty list clears
		// them.
		if ( $kind === 'quotation' && array_key_exists( 'translations', $record ) ) {
			$translationPropertyId = $this->config->translationPropertyId();
			if ( $translationPropertyId !== null ) {
				$specs[$translationPropertyId] = [];
				foreach ( $record['translations'] as $row ) {
					$specs[$translationPropertyId][] = new MonolingualTextValue(
						(string)$row['language'],
						(string)$row['content']
					);
				}
			}
		}

		// The math accompanying notes (rich wikitext, escaped-at-rest):
		// `note` renders below the expression, `preNote` above it.
		$noteProperties = [
			'note' => $this->config->notePropertyId(),
			'preNote' => $this->config->preNotePropertyId(),
		];
		if ( $kind === 'math' ) {
			foreach ( $noteProperties as $field => $notePropertyId ) {
				if ( $notePropertyId !== null && isset( $record[$field] ) && $record[$field] !== '' ) {
					$specs[$notePropertyId] = new StringValue( (string)$record[$field] );
				}
			}
		}

		$provenance = $this->config->provenancePropertyIds();
		// `attributedTo` is multi-value: one `attributed to` statement per
		// confirmed author (WikibaseStatement order preserved).
		$authorIds = $this->splitItemIds( (string)( $record['attributedTo'] ?? '' ) );
		if ( $authorIds !== [] && isset( $provenance['attributedTo'] ) ) {
			$specs[$provenance['attributedTo']] = array_map(
				static fn ( string $id ): EntityIdValue => new EntityIdValue( new ItemId( strtoupper( $id ) ) ),
				$authorIds
			);
		}
		$sourceId = trim( (string)( $record['source'] ?? '' ) );
		if ( $sourceId !== '' && isset( $provenance['source'] ) && preg_match( '/^Q[1-9]\d*$/', $sourceId ) === 1 ) {
			$specs[$provenance['source']] = new EntityIdValue( new ItemId( $sourceId ) );
		}
		$sourceUrl = trim( (string)( $record['sourceUrl'] ?? '' ) );
		if ( $sourceUrl !== '' && isset( $provenance['sourceUrl'] ) && $this->isHttpUrl( $sourceUrl ) ) {
			$specs[$provenance['sourceUrl']] = new StringValue( $sourceUrl );
		}
		$date = trim( (string)( $record['date'] ?? '' ) );
		if ( $date !== '' && isset( $provenance['date'] ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) === 1 ) {
			$specs[$provenance['date']] = new TimeValue(
				sprintf( '+%04d-%02d-%02dT00:00:00Z', (int)$m[1], (int)$m[2], (int)$m[3] ),
				0, 0, 0,
				TimeValue::PRECISION_DAY,
				'http://www.wikidata.org/entity/Q1985727'
			);
		}

		$subjectSpecs = [
			'math' => [ 'describes', $this->config->describesPropertyId() ],
			'code-snippet' => [ 'implementationOf', $this->config->implementationOfPropertyId() ],
		];
		if ( isset( $subjectSpecs[$kind] ) ) {
			[$field, $propertyId] = $subjectSpecs[$kind];
			if ( $propertyId !== null ) {
				foreach ( $this->splitItemIds( (string)( $record[$field] ?? '' ) ) as $id ) {
					$specs[$propertyId][] = new EntityIdValue( new ItemId( $id ) );
				}
			}
		}

		return $specs;
	}

	/** @param array<string,mixed> $record */
	public function buildItem( string $kind, array $record ): Item {
		$item = new Item();
		$labelLanguage = (string)( $record['labelLanguage'] ?? 'en' );
		$item->setLabel( $labelLanguage, (string)( $record['label'] ?? '' ) );
		foreach ( $this->statementSpecs( $kind, $record ) as $propertyId => $value ) {
			foreach ( is_array( $value ) ? $value : [ $value ] as $single ) {
				$item->getStatements()->addNewStatement(
					new PropertyValueSnak( PropertyIdFactory::create( $propertyId ), $single )
				);
			}
		}
		$additional = $record['additionalStatements'] ?? [];
		if ( is_array( $additional ) && $additional !== [] ) {
			$managed = array_keys( $this->statementSpecs( $kind, $record ) );
			$managed[] = $this->config->instanceOfPropertyId();
			$built = AdditionalStatements::dataValues(
				$additional,
				array_values( array_unique( $managed ) )
			);
			if ( $built['error'] !== null ) {
				throw new \InvalidArgumentException( $built['error'] );
			}
			foreach ( $built['values'] as $propertyId => $values ) {
				foreach ( $values as $single ) {
					$item->getStatements()->addNewStatement(
						new PropertyValueSnak( PropertyIdFactory::create( $propertyId ), $single )
					);
				}
			}
		}
		return $item;
	}

	/**
	 * No-clobber update: replaces statements for provided fields, keeps the
	 * rest, never changes the class. Mutates $item; the caller persists.
	 *
	 * @param array<string,mixed> $record
	 */
	public function applyUpdate( string $kind, Item $item, array $record ): void {
		$provided = array_filter( $record, static fn ( $v ) => $v !== null && $v !== '' );
		$managed = [];
		foreach ( $this->statementSpecs( $kind, $provided ) as $propertyId => $_ ) {
			$managed[] = $propertyId;
		}
		$managed = array_values( array_unique( $managed ) );
		if ( $managed !== [] ) {
			$kept = new StatementList();
			foreach ( $item->getStatements() as $statement ) {
				if ( !in_array( $statement->getPropertyId()->getSerialization(), $managed, true ) ) {
					$kept->addStatement( $statement );
				}
			}
			$item->setStatements( $kept );
			foreach ( $this->statementSpecs( $kind, $record ) as $propertyId => $value ) {
				foreach ( is_array( $value ) ? $value : [ $value ] as $single ) {
					$item->getStatements()->addNewStatement(
						new PropertyValueSnak( PropertyIdFactory::create( $propertyId ), $single )
					);
				}
			}
			// The item id is known on updates — assign GUIDs to the newly
			// added statements now (the entity-page client matches statements
			// to the DOM by GUID; a GUID-less statement renders as an empty
			// edit-mode row for logged-in users).
			StatementGuidAssigner::ensureGuids( $item, new GuidGenerator() );
		}
		$additional = $record['additionalStatements'] ?? [];
		if ( is_array( $additional ) && $additional !== [] ) {
			$additionalManaged = $managed;
			$additionalManaged[] = $this->config->instanceOfPropertyId();
			$error = AdditionalStatements::applyDiff(
				$item,
				$additional,
				array_values( array_unique( $additionalManaged ) )
			);
			if ( $error !== null ) {
				throw new \InvalidArgumentException( $error );
			}
			StatementGuidAssigner::ensureGuids( $item, new GuidGenerator() );
		}
		$label = trim( (string)( $record['label'] ?? '' ) );
		if ( $label !== '' ) {
			$item->setLabel( (string)( $record['labelLanguage'] ?? 'en' ), $label );
		}
	}

	/** @return string[] */
	private function splitItemIds( string $value ): array {
		$out = [];
		foreach ( preg_split( '/[,;]/', $value ) ?: [] as $part ) {
			$part = trim( $part );
			if ( $part !== '' && !in_array( $part, $out, true ) ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	private function isHttpUrl( string $url ): bool {
		return preg_match( '#^https?://\S+$#i', $url ) === 1;
	}

}
