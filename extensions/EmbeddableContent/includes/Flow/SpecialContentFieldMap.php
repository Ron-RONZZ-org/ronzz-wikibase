<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

/**
 * The entity-mode field vocabulary of the Add* special-content flow
 * (quotation / math / code-snippet) — the contract the action=addspecialcontent
 * API module accepts and the discovery endpoint reports. Mirrors
 * SourceFieldMap: the machine-facing vocabulary, separate from the browser
 * form's own review fields.
 *
 * @license GPL-2.0-or-later
 */
final class SpecialContentFieldMap {

	public const KINDS = [ 'quotation', 'math', 'code-snippet' ];

	public const ALL_FIELDS = [
		'label',
		'content',
		'labelLanguage',
		'language',
		'programmingLanguage',
		'describes',
		'implementationOf',
		'attributedTo',
		'source',
		'sourceUrl',
		'date',
	];

	/** Fields whose value is an item id (Q-number). */
	private const ENTITY_FIELDS = [
		'programmingLanguage',
		'describes',
		'implementationOf',
		'attributedTo',
		'source',
	];

	/** kind => fields the flow accepts for it. */
	private const KIND_FIELDS = [
		'quotation' => [ 'label', 'content', 'labelLanguage', 'language', 'attributedTo', 'source', 'sourceUrl', 'date' ],
		'math' => [ 'label', 'content', 'labelLanguage', 'describes', 'attributedTo', 'source', 'sourceUrl', 'date' ],
		'code-snippet' => [ 'label', 'content', 'labelLanguage', 'programmingLanguage', 'implementationOf', 'attributedTo', 'source', 'sourceUrl', 'date' ],
	];

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
	 * @return string[] required on create (never on update)
	 */
	public static function requiredOnCreate( string $kind ): array {
		$required = [ 'label', 'content' ];
		if ( $kind === 'quotation' ) {
			// The form requires the attribution for quotations.
			$required[] = 'attributedTo';
		}
		return $required;
	}
}
