<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

/**
 * The entity-mode field vocabulary of the AddSource flow — the contract the
 * action=addsource API module accepts and the action=addsource-fields
 * discovery endpoint reports. One source of truth for class→field exposure
 * and required-on-create rules, so the API module, the discovery endpoint
 * and the MCP describe tool can never drift apart (the 
 * "webpage rejects authors yet demands one" bug of 2026-08-30 was exactly
 * that drift).
 *
 * The browser form's own review-field vocabulary (issuedYear, publishedIn,
 * accessMode, …) is separate and stays with SpecialAddSource; this map is
 * the machine-facing contract.
 *
 * @license GPL-2.0-or-later
 */
final class SourceFieldMap {

	/** Every class the AddSource flow can create. */
	public const CLASS_KEYS = [
		'book',
		'scholarly-article',
		'website',
		'webpage',
		'song',
		'film',
		'video',
		'youtube-channel',
		'youtube-video',
		'book-excerpt',
	];

	/** Every field the entity-mode vocabulary knows. */
	public const ALL_FIELDS = [
		'title',
		'description',
		'authors',
		'publisher',
		'journal',
		'volume',
		'issue',
		'pages',
		'chapters',
		'year',
		'isbn',
		'doi',
		'wikidataId',
		'openalexWorkId',
		'pubmedId',
		'url',
		'duration',
		'youtubeChannelId',
		'youtubeVideoId',
		'accessUrl',
		'parent',
	];

	/** The parent class key each child class requires. */
	public const PARENT_CLASS = [
		'webpage' => 'website',
		'youtube-video' => 'youtube-channel',
		'book-excerpt' => 'book',
	];

	/** The API class keys as the Special:AddSource flow spells them. */
	private const FORM_KEYS = [
		'scholarly-article' => 'scholarlyArticle',
		'youtube-channel' => 'youtubeChannel',
		'youtube-video' => 'youtubeVideo',
		'book-excerpt' => 'bookExcerpt',
	];

	/** The API class key for a form class key (identity for the plain ones). */
	public static function formKey( string $classKey ): string {
		return self::FORM_KEYS[$classKey] ?? $classKey;
	}

	/** Fields whose value is an entity id (Q-number), never a bare string. */
	private const ENTITY_FIELDS = [ 'authors', 'publisher', 'journal', 'parent' ];

	/** The fields each class exposes. Kept in step with the Special:AddSource
	 *  review form: every class has an authors field (required except for
	 *  book-excerpt), child classes carry parent, website carries no year. */
	private const CLASS_FIELDS = [
		'book' => [ 'title', 'description', 'authors', 'publisher', 'pages', 'year', 'isbn', 'accessUrl', 'wikidataId' ],
		'scholarly-article' => [ 'title', 'description', 'authors', 'journal', 'publisher', 'volume', 'issue', 'pages', 'year', 'doi', 'accessUrl', 'wikidataId', 'openalexWorkId', 'pubmedId' ],
		'website' => [ 'title', 'description', 'authors', 'url', 'wikidataId' ],
		'webpage' => [ 'title', 'description', 'authors', 'url', 'year', 'parent', 'wikidataId' ],
		'song' => [ 'title', 'description', 'authors', 'year', 'duration', 'accessUrl' ],
		'film' => [ 'title', 'description', 'authors', 'year', 'duration', 'accessUrl' ],
		'video' => [ 'title', 'description', 'authors', 'year', 'duration', 'url' ],
		'youtube-channel' => [ 'title', 'description', 'authors', 'year', 'url', 'youtubeChannelId' ],
		'youtube-video' => [ 'title', 'description', 'authors', 'year', 'duration', 'url', 'youtubeVideoId', 'parent' ],
		'book-excerpt' => [ 'title', 'description', 'authors', 'pages', 'volume', 'chapters', 'year', 'accessUrl', 'parent' ],
	];

	/** @return string[] */
	public static function fieldsForClass( string $classKey ): array {
		return self::CLASS_FIELDS[$classKey] ?? [];
	}

	public static function acceptsField( string $classKey, string $field ): bool {
		return in_array( $field, self::CLASS_FIELDS[$classKey] ?? [], true );
	}

	public static function isEntityTyped( string $field ): bool {
		return in_array( $field, self::ENTITY_FIELDS, true );
	}

	public static function isChildClass( string $classKey ): bool {
		return isset( self::PARENT_CLASS[$classKey] );
	}

	/**
	 * Fields that must be present when creating (never on update — update
	 * replaces only the statements for provided fields).
	 *
	 * @return string[]
	 */
	public static function requiredOnCreate( string $classKey ): array {
		$required = [ 'title' ];
		$fields = self::CLASS_FIELDS[$classKey] ?? [];
		if ( in_array( 'authors', $fields, true ) && $classKey !== 'book-excerpt' ) {
			// book-excerpt may copy the parent book's authors when blank.
			$required[] = 'authors';
		}
		if ( self::isChildClass( $classKey ) ) {
			$required[] = 'parent';
		}
		return $required;
	}
}
