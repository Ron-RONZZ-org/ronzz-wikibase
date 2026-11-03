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

	/** The API kind as the config / Special:Add* flow spells it. */
	private const FORM_KEYS = [ 'code-snippet' => 'code' ];

	/** The config kind key for an API kind (identity for the plain ones). */
	public static function formKey( string $kind ): string {
		return self::FORM_KEYS[$kind] ?? $kind;
	}

	public const ALL_FIELDS = [
		'label',
		'content',
		'note',
		'preNote',
		'labelLanguage',
		'language',
		'programmingLanguage',
		'describes',
		'implementationOf',
		'attributedTo',
		'source',
		'sourceUrl',
		'date',
		// Quotation only: the added translations, a list of
		// { language: string, content: string } rows (the AddQuotation
		// "Add translation" field).
		'translations',
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
		'quotation' => [ 'label', 'content', 'labelLanguage', 'language', 'translations', 'attributedTo', 'source', 'sourceUrl', 'date' ],
		'math' => [ 'label', 'content', 'note', 'preNote', 'labelLanguage', 'describes', 'attributedTo', 'source', 'sourceUrl', 'date' ],
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
		return [ 'label', 'content' ];
	}

	/**
	 * Groups of fields where at least ONE member is required on create.
	 * A quotation needs an author OR a source; when only a single-author
	 * source is given the author is inherited (QuotationSourceAuthor).
	 *
	 * @return string[][]
	 */
	public static function requiredAnyOf( string $kind ): array {
		return $kind === 'quotation' ? [ [ 'attributedTo', 'source' ] ] : [];
	}
}
