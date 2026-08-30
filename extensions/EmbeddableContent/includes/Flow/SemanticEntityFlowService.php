<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\EmbeddableContentConfig;
use Wikibase\DataModel\DataValue;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\StatementList;

/**
 * The entity-mode semantic-entity pipeline (person / software / collective /
 * fictional-character / other) — the logic the action=addsemanticentity API
 * module (and through it the MCP embeddable-add-semantic-entity tool) runs.
 * Pure PHP, unit-testable. Field exposure is owned by SemanticEntityFieldMap;
 * the label derivation (person given/family, fictional-character suffix) and
 * the fictional-character auto-description mirror the forms. Portraits and
 * logos (image uploads) stay browser-only, and kind=other takes no raw
 * statements (use wbeditentity for those).
 *
 * @license GPL-2.0-or-later
 */
final class SemanticEntityFlowService {

	public const ERROR_LABEL_REQUIRED = 'label is required when creating a %s item.';
	public const ERROR_NAME_REQUIRED = 'givenName or familyName is required when creating a person / fictional-character item.';
	public const ERROR_INSTANCE_OF_REQUIRED = 'instanceOf is required when creating an other item.';

	/** The AddCollective picker presets: preset key => agentClasses() key. */
	private const COLLECTIVE_PRESETS = [
		'organization' => 'organization',
		'group-of-humans' => 'groupOfHumans',
		'private-company' => 'privateCompany',
		'public-company' => 'publicCompany',
		'non-profit-organization' => 'nonProfitOrganization',
		'governmental-agency' => 'governmentalAgency',
		'music-band' => 'musicBand',
		'educational-institution' => 'educationalInstitution',
		'research-institute' => 'researchInstitute',
		'political-party' => 'politicalParty',
		'trade-union' => 'tradeUnion',
		'religious-organization' => 'religiousOrganization',
		'sports-team' => 'sportsTeam',
	];

	/**
	 * @param \Closure(string $messageKey, string[] $params): string $message
	 */
	public function __construct(
		private readonly EmbeddableContentConfig $config,
		private readonly EntityLookup $lookup,
		private readonly \Closure $message
	) {
	}

	/**
	 * Validates and normalizes a record. Returns an error string, or null
	 * after mutating $record (collectiveClass resolved to an item id, the
	 * fictional-character description auto-generated, person label parts
	 * kept as-is).
	 *
	 * @param array<string,mixed> $record
	 */
	public function prepare( string $kind, array &$record, bool $creating ): ?string {
		if ( !in_array( $kind, SemanticEntityFieldMap::KINDS, true ) ) {
			return "kind {$kind} is not one of " . implode( ', ', SemanticEntityFieldMap::KINDS ) . '.';
		}
		$unknown = array_diff( array_keys( $record ), SemanticEntityFieldMap::ALL_FIELDS );
		if ( $unknown !== [] ) {
			return 'unknown field(s): ' . implode( ', ', $unknown ) . '.';
		}
		$provided = array_filter( $record, static fn ( $v ) => $v !== null && $v !== '' );
		$disallowed = array_diff( array_keys( $provided ), SemanticEntityFieldMap::fieldsForKind( $kind ) );
		if ( $disallowed !== [] ) {
			return "kind {$kind} does not accept the field(s) " . implode( ', ', $disallowed )
				. '. Its fields are ' . implode( ', ', SemanticEntityFieldMap::fieldsForKind( $kind ) ) . '.';
		}

		if ( $creating ) {
			$required = SemanticEntityFieldMap::requiredOnCreate( $kind );
			if ( in_array( 'label', $required, true ) && !isset( $provided['label'] ) ) {
				return sprintf( self::ERROR_LABEL_REQUIRED, $kind );
			}
			if ( in_array( 'givenName', $required, true )
				&& !isset( $provided['givenName'] ) && !isset( $provided['familyName'] )
			) {
				return self::ERROR_NAME_REQUIRED;
			}
			if ( in_array( 'instanceOf', $required, true ) && !isset( $provided['instanceOf'] ) ) {
				return self::ERROR_INSTANCE_OF_REQUIRED;
			}
		}

		foreach ( [ 'dateOfBirth', 'dateOfDeath' ] as $dateField ) {
			$date = trim( (string)( $record[$dateField] ?? '' ) );
			if ( $date !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) !== 1 ) {
				return "{$dateField} \"{$date}\" is not YYYY-MM-DD.";
			}
		}
		foreach ( [ 'officialWebsite', 'sourceCodeRepository', 'documentationUrl' ] as $urlField ) {
			$url = trim( (string)( $record[$urlField] ?? '' ) );
			if ( $url !== '' && !$this->isHttpUrl( $url ) ) {
				return "{$urlField} \"{$url}\" is not an http(s) URL.";
			}
		}
		foreach ( [ 'placeOfBirth', 'placeOfDeath', 'parentOrganization', 'instanceOf', 'developer', 'license', 'operatingSystem', 'userInterface', 'hasUse' ] as $entityField ) {
			$id = trim( (string)( $record[$entityField] ?? '' ) );
			if ( $id !== '' && preg_match( '/^Q[1-9]\d*$/', $id ) !== 1 ) {
				return "{$entityField} \"{$id}\" is not an item ID.";
			}
		}

		if ( $kind === 'collective' ) {
			$class = trim( (string)( $record['collectiveClass'] ?? '' ) );
			if ( $class === '' ) {
				$record['collectiveClass'] = $this->config->agentClasses()['organization'] ?? '';
			} elseif ( preg_match( '/^Q[1-9]\d*$/', $class ) !== 1 ) {
				// A picker preset key, or an agent-class key.
				$presetKey = self::COLLECTIVE_PRESETS[$class] ?? $class;
				$record['collectiveClass'] = $this->config->agentClasses()[$presetKey] ?? '';
				if ( $record['collectiveClass'] === '' ) {
					return "collectiveClass \"{$class}\" is neither an item ID nor a known preset.";
				}
			}
		}

		foreach ( [ 'presentInWork' ] as $listField ) {
			foreach ( $this->splitItemIds( (string)( $record[$listField] ?? '' ) ) as $id ) {
				if ( preg_match( '/^Q[1-9]\d*$/', $id ) !== 1 ) {
					return "{$listField} contains \"{$id}\", which is not an item ID.";
				}
			}
		}

		if ( $kind === 'fictional-character' && trim( (string)( $record['description'] ?? '' ) ) === '' ) {
			$record['description'] = $this->fictionalDescription( (string)( $record['presentInWork'] ?? '' ) );
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $record
	 * @return array<string, DataValue|DataValue[]>
	 */
	public function statementSpecs( string $kind, array $record ): array {
		$specs = [];
		switch ( $kind ) {
			case 'person':
				$person = $this->config->personPropertyIds();
				foreach ( [ 'dateOfBirth' => 'dateOfBirth', 'dateOfDeath' => 'dateOfDeath' ] as $field => $key ) {
					$date = trim( (string)( $record[$field] ?? '' ) );
					if ( $date !== '' && isset( $person[$key] ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) === 1 ) {
						$specs[$person[$key]] = $this->dayTime( $m );
					}
				}
				foreach ( [ 'placeOfBirth' => 'placeOfBirth', 'placeOfDeath' => 'placeOfDeath' ] as $field => $key ) {
					$id = trim( (string)( $record[$field] ?? '' ) );
					if ( $id !== '' && isset( $person[$key] ) ) {
						$specs[$person[$key]] = new EntityIdValue( new ItemId( $id ) );
					}
				}
				foreach ( [ 'orcid' => 'orcid', 'viafId' => 'viaf', 'isni' => 'isni', 'wikidataId' => 'wikidata', 'openalexAuthorId' => 'openalexAuthor' ] as $field => $key ) {
					$value = trim( (string)( $record[$field] ?? '' ) );
					$property = $this->config->externalIdPropertyIds()[$key] ?? null;
					if ( $value !== '' && $property !== null ) {
						$specs[$property] = new StringValue( $value );
					}
				}
				$website = trim( (string)( $record['officialWebsite'] ?? '' ) );
				if ( $website !== '' && isset( $person['officialWebsite'] ) ) {
					$specs[$person['officialWebsite']] = new StringValue( $website );
				}
				break;

			case 'software':
				$foss = $this->config->fossPropertyIds();
				foreach ( [ 'developer', 'license', 'operatingSystem', 'userInterface', 'hasUse' ] as $field ) {
					$id = trim( (string)( $record[$field] ?? '' ) );
					if ( $id !== '' && isset( $foss[$field] ) ) {
						$specs[$foss[$field]] = new EntityIdValue( new ItemId( $id ) );
					}
				}
				$language = trim( (string)( $record['programmingLanguage'] ?? '' ) );
				if ( $language !== '' && preg_match( '/^Q[1-9]\d*$/', $language ) === 1 ) {
					$specs[$this->config->programmingLanguagePropertyId()] = new EntityIdValue( new ItemId( $language ) );
				}
				foreach ( [ 'sourceCodeRepository' => 'sourceRepository', 'documentationUrl' => 'documentationUrl', 'officialWebsite' => 'officialWebsite' ] as $field => $key ) {
					$url = trim( (string)( $record[$field] ?? '' ) );
					if ( $url !== '' && isset( $foss[$key] ) ) {
						$specs[$foss[$key]] = new StringValue( $url );
					}
				}
				$wikidata = trim( (string)( $record['wikidataId'] ?? '' ) );
				$wikidataProp = $this->config->externalIdPropertyIds()['wikidata'] ?? null;
				if ( $wikidata !== '' && $wikidataProp !== null ) {
					$specs[$wikidataProp] = new StringValue( $wikidata );
				}
				break;

			case 'collective':
				$collective = $this->config->collectivePropertyIds();
				$class = trim( (string)( $record['collectiveClass'] ?? '' ) );
				if ( $class !== '' && preg_match( '/^Q[1-9]\d*$/', $class ) === 1 ) {
					$specs[$this->config->instanceOfPropertyId()] = new EntityIdValue( new ItemId( $class ) );
				}
				$parent = trim( (string)( $record['parentOrganization'] ?? '' ) );
				if ( $parent !== '' && isset( $collective['parentOrganization'] ) ) {
					$specs[$collective['parentOrganization']] = new EntityIdValue( new ItemId( $parent ) );
				}
				$website = trim( (string)( $record['officialWebsite'] ?? '' ) );
				if ( $website !== '' && isset( $collective['officialWebsite'] ) ) {
					$specs[$collective['officialWebsite']] = new StringValue( $website );
				}
				$wikidata = trim( (string)( $record['wikidataId'] ?? '' ) );
				$wikidataProp = $this->config->externalIdPropertyIds()['wikidata'] ?? null;
				if ( $wikidata !== '' && $wikidataProp !== null ) {
					$specs[$wikidataProp] = new StringValue( $wikidata );
				}
				break;

			case 'fictional-character':
				$appearsIn = $this->config->fictionalCharacterPropertyIds()['appearsIn'] ?? null;
				if ( $appearsIn !== null ) {
					foreach ( $this->splitItemIds( (string)( $record['presentInWork'] ?? '' ) ) as $id ) {
						$specs[$appearsIn][] = new EntityIdValue( new ItemId( $id ) );
					}
				}
				break;

			case 'other':
				$instanceOf = trim( (string)( $record['instanceOf'] ?? '' ) );
				if ( $instanceOf !== '' && preg_match( '/^Q[1-9]\d*$/', $instanceOf ) === 1 ) {
					$specs[$this->config->instanceOfPropertyId()] = new EntityIdValue( new ItemId( $instanceOf ) );
				}
				break;
		}
		return $specs;
	}

	/** @param array<string,mixed> $record */
	public function labelFor( string $kind, array $record ): string {
		switch ( $kind ) {
			case 'person':
				$given = trim( (string)( $record['givenName'] ?? '' ) );
				$family = trim( (string)( $record['familyName'] ?? '' ) );
				return trim( $given . ' ' . $family );
			case 'fictional-character':
				$given = trim( (string)( $record['givenName'] ?? '' ) );
				$family = trim( (string)( $record['familyName'] ?? '' ) );
				$name = trim( $given . ' ' . $family );
				return $name === '' ? '' : $name . ' (fictional character)';
			default:
				return trim( (string)( $record['label'] ?? '' ) );
		}
	}

	/** @param array<string,mixed> $record */
	public function buildItem( string $kind, array $record ): Item {
		$item = new Item();
		$item->setLabel( 'en', $this->labelFor( $kind, $record ) );
		$description = trim( (string)( $record['description'] ?? '' ) );
		if ( $description !== '' ) {
			$item->setDescription( 'en', $description );
		}
		foreach ( $this->statementSpecs( $kind, $record ) as $propertyId => $value ) {
			foreach ( is_array( $value ) ? $value : [ $value ] as $single ) {
				$item->getStatements()->addNewStatement(
					new PropertyValueSnak( new PropertyId( $propertyId ), $single )
				);
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
						new PropertyValueSnak( new PropertyId( $propertyId ), $single )
					);
				}
			}
		}
		$label = $this->labelFor( $kind, $record );
		if ( $label !== '' ) {
			$item->setLabel( 'en', $label );
		}
		$description = trim( (string)( $record['description'] ?? '' ) );
		if ( $description !== '' ) {
			$item->setDescription( 'en', $description );
		}
	}

	/** "fictional character in {labels…}" from the present-in-work items. */
	private function fictionalDescription( string $presentInWork ): string {
		$labels = [];
		foreach ( $this->splitItemIds( $presentInWork ) as $id ) {
			$work = $this->lookup->getEntity( new ItemId( $id ) );
			if ( $work instanceof Item ) {
				$term = $work->getLabels()->getByLanguage( 'en' );
				if ( $term !== null ) {
					$labels[] = $term->getText();
				}
			}
		}
		return $labels !== [] ? 'fictional character in ' . implode( ', ', $labels ) : '';
	}

	private function dayTime( array $m ): TimeValue {
		return new TimeValue(
			sprintf( '+%04d-%02d-%02dT00:00:00Z', (int)$m[1], (int)$m[2], (int)$m[3] ),
			0, 0, 0,
			TimeValue::PRECISION_DAY,
			'http://www.wikidata.org/entity/Q1985727'
		);
	}

	/** @return string[] */
	private function splitItemIds( string $value ): array {
		$out = [];
		foreach ( preg_split( '/[,;]/', $value ) ?: [] as $part ) {
			$part = trim( $part );
			if ( $part !== '' ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	private function isHttpUrl( string $url ): bool {
		return preg_match( '#^https?://\S+$#i', $url ) === 1;
	}
}
