<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

/**
 * The entity-mode field vocabulary of the Add* semantic-entity flow
 * (person / software / collective / fictional-character / other) — the
 * contract the action=addsemanticentity API module accepts and the
 * discovery endpoint reports. Mirrors SourceFieldMap: the machine-facing
 * vocabulary, separate from the browser forms' own review fields.
 *
 * @license GPL-2.0-or-later
 */
final class SemanticEntityFieldMap {

	public const KINDS = [ 'person', 'software', 'collective', 'fictional-character', 'other' ];

	public const ALL_FIELDS = [
		'label',
		'description',
		'givenName',
		'familyName',
		'alias',
		'dateOfBirth',
		'placeOfBirth',
		'dateOfDeath',
		'placeOfDeath',
		'orcid',
		'viafId',
		'isni',
		'wikidataId',
		'openalexAuthorId',
		'officialWebsite',
		'developer',
		'license',
		'programmingLanguage',
		'operatingSystem',
		'userInterface',
		'hasUse',
		'sourceCodeRepository',
		'documentationUrl',
		'collectiveClass',
		'parentOrganization',
		'presentInWork',
		'instanceOf',
		// Page-kind decision (FOSS: vs Software: classic page) — a PAGE
		// attribute, not a statement field; it rides the software record so
		// the flow service can write the matching item class.
		'pageKind',
	];

	/**
	 * Fields the API accepts as a TYPED parameter rather than a generic
	 * string field: the module declares them explicitly in getAllowedParams.
	 * pageKind is a foss|software enum. Kept here (next to the contract)
	 * so fieldParams() can derive from ALL_FIELDS and the two can never
	 * drift — a field added to the map is accepted unless it is named here.
	 */
	public const API_TYPED_FIELDS = [ 'pageKind' ];

	/** Fields whose value is an item id (Q-number). */
	private const ENTITY_FIELDS = [
		'developer',
		'license',
		'programmingLanguage',
		'operatingSystem',
		'userInterface',
		'hasUse',
		'collectiveClass',
		'parentOrganization',
		'presentInWork',
		'instanceOf',
	];

	/** kind => fields the flow accepts for it. */
	private const KIND_FIELDS = [
		'person' => [ 'givenName', 'familyName', 'description', 'dateOfBirth', 'placeOfBirth', 'dateOfDeath', 'placeOfDeath', 'orcid', 'viafId', 'isni', 'wikidataId', 'openalexAuthorId', 'officialWebsite' ],
		'software' => [ 'label', 'description', 'developer', 'license', 'programmingLanguage', 'operatingSystem', 'userInterface', 'hasUse', 'officialWebsite', 'sourceCodeRepository', 'documentationUrl', 'wikidataId', 'pageKind' ],
		'collective' => [ 'label', 'description', 'collectiveClass', 'parentOrganization', 'officialWebsite', 'wikidataId' ],
		'fictional-character' => [ 'givenName', 'familyName', 'alias', 'description', 'presentInWork' ],
		'other' => [ 'label', 'description', 'instanceOf' ],
	];

	/**
	 * The generic string fields the action=addsemanticentity module accepts —
	 * ALL_FIELDS minus the typed params (API_TYPED_FIELDS). The one contract
	 * the API params derive from.
	 *
	 * @return string[]
	 */
	public static function apiParamFields(): array {
		return array_values( array_diff( self::ALL_FIELDS, self::API_TYPED_FIELDS ) );
	}

	/** @return string[] */
	public static function fieldsForKind( string $kind ): array {
		return self::KIND_FIELDS[$kind] ?? [];
	}

	public static function acceptsField( string $kind, string $field ): bool {
		return in_array( $field, self::KIND_FIELDS[$kind] ?? [], true );
	}

	public static function isEntityTyped( string $field ): bool {
		return in_array( $field, self::ENTITY_FIELDS, true );
	}

	/**
	 * @return string[] required on create (never on update). The person /
	 * fictional-character rule is a disjunction (givenName OR familyName),
	 * expressed as two entries.
	 */
	public static function requiredOnCreate( string $kind ): array {
		switch ( $kind ) {
			case 'person':
			case 'fictional-character':
				return [ 'givenName', 'familyName' ];
			case 'other':
				return [ 'label', 'instanceOf' ];
			default:
				return [ 'label' ];
		}
	}
}
