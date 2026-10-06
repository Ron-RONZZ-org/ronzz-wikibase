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
		// Zotero/CSL-aligned batch.
		'newspaper-article',
		'magazine-article',
		'conference-paper',
		'report',
		'document',
		'thesis',
		'manuscript',
		'patent',
		'legal-case',
		'legislation',
		'bill',
		'treaty',
		'interview',
		'map',
		'presentation',
		'dataset',
		// Catch-all (historical texts, inscriptions, …).
		'text',
		// A particular clause/provision of a legislation (child of
		// legislation; carries a monolingual payload + translations).
		'law',
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
		// Zotero/CSL-aligned batch: legal/official-document facts.
		'court',
		'territorialJurisdiction',
		'territorialJurisdictionLabel',
		'caseNumber',
		'patentNumber',
		'reportNumber',
		'legislationNumber',
		// International legal texts (a boolean marker replacing territorial
		// jurisdiction).
		'international',
		// The language(s) of the source (BCP-47 codes): written as one
		// `language` string statement per code. `language` is the primary
		// (or the `__other__` sentinel, resolved from `otherLanguage` free
		// text); `additionalLanguages` is a comma-separated code list.
		// `labelLanguage` is the SEPARATE term language the item label/
		// description are stored under (default `en`) — a French-language
		// book can carry an English label.
		'language',
		'additionalLanguages',
		'otherLanguage',
		'labelLanguage',
		// A legal provision's identifier within its legislation, plus the
		// clause text (monolingual, inherited language) and its added
		// translations (the AddQuotation shape).
		'referenceCode',
		'content',
		'translations',
	];

	/** The parent class key each child class requires. */
	public const PARENT_CLASS = [
		'webpage' => 'website',
		'youtube-video' => 'youtube-channel',
		'book-excerpt' => 'book',
		// A legal provision belongs to its legislation.
		'law' => 'legislation',
	];

	/** The API class keys as the Special:AddSource flow spells them. */
	private const FORM_KEYS = [
		'scholarly-article' => 'scholarlyArticle',
		'youtube-channel' => 'youtubeChannel',
		'youtube-video' => 'youtubeVideo',
		'book-excerpt' => 'bookExcerpt',
		'newspaper-article' => 'newspaperArticle',
		'magazine-article' => 'magazineArticle',
		'conference-paper' => 'conferencePaper',
		'legal-case' => 'legalCase',
	];

	/** The API class key for a form class key (identity for the plain ones). */
	public static function formKey( string $classKey ): string {
		return self::FORM_KEYS[$classKey] ?? $classKey;
	}

	/** The form class key for an API class key (identity for the plain ones). */
	public static function apiKey( string $formKey ): string {
		$apiKey = array_search( $formKey, self::FORM_KEYS, true );
		return $apiKey !== false ? $apiKey : $formKey;
	}

	/** Fields whose value is an entity id (Q-number), never a bare string. */
	private const ENTITY_FIELDS = [ 'authors', 'publisher', 'journal', 'parent', 'court' ];

	/** The fields each class exposes. Kept in step with the Special:AddSource
	 *  review form: every class has an authors field (required except for
	 *  book-excerpt), child classes carry parent, website carries no year. */
	private const CLASS_FIELDS = [
		'book' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'publisher', 'pages', 'year', 'isbn', 'accessUrl', 'wikidataId' ],
		'scholarly-article' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'journal', 'publisher', 'volume', 'issue', 'pages', 'year', 'doi', 'accessUrl', 'wikidataId', 'openalexWorkId', 'pubmedId' ],
		'website' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'url', 'wikidataId' ],
		'webpage' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'url', 'year', 'parent', 'wikidataId' ],
		'song' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'year', 'duration', 'accessUrl' ],
		'film' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'year', 'duration', 'accessUrl' ],
		'video' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'year', 'duration', 'url' ],
		'youtube-channel' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'year', 'url', 'youtubeChannelId' ],
		'youtube-video' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'year', 'duration', 'url', 'youtubeVideoId', 'parent' ],
		'book-excerpt' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'pages', 'volume', 'chapters', 'year', 'accessUrl', 'parent' ],
		// Zotero/CSL-aligned batch. authors is omitted where it is not
		// meaningful (legal texts) — requiredOnCreate follows the exposure.
		'newspaper-article' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'publisher', 'pages', 'year', 'url', 'accessUrl', 'wikidataId' ],
		'magazine-article' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'publisher', 'volume', 'issue', 'pages', 'year', 'url', 'accessUrl', 'wikidataId' ],
		'conference-paper' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'publisher', 'pages', 'year', 'doi', 'url', 'accessUrl', 'wikidataId', 'openalexWorkId' ],
		'report' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'publisher', 'reportNumber', 'year', 'url', 'accessUrl', 'wikidataId' ],
		'document' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'publisher', 'reportNumber', 'year', 'url', 'accessUrl', 'wikidataId' ],
		'thesis' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'publisher', 'year', 'url', 'accessUrl', 'wikidataId' ],
		'manuscript' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'year', 'url', 'accessUrl', 'wikidataId' ],
		'patent' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'patentNumber', 'year', 'url', 'wikidataId' ],
		'legal-case' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'court', 'territorialJurisdiction', 'territorialJurisdictionLabel', 'caseNumber', 'international', 'year', 'url', 'wikidataId' ],
		'legislation' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'territorialJurisdiction', 'territorialJurisdictionLabel', 'legislationNumber', 'international', 'year', 'url', 'wikidataId' ],
		'bill' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'territorialJurisdiction', 'territorialJurisdictionLabel', 'legislationNumber', 'international', 'year', 'url', 'wikidataId' ],
		'treaty' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'territorialJurisdiction', 'territorialJurisdictionLabel', 'international', 'year', 'url', 'wikidataId' ],
		'interview' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'publisher', 'year', 'url', 'wikidataId' ],
		'map' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'publisher', 'year', 'url', 'accessUrl', 'wikidataId' ],
		'presentation' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'year', 'url', 'wikidataId' ],
		'dataset' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'publisher', 'year', 'url', 'accessUrl', 'wikidataId' ],
		// Catch-all: any text — historical texts, inscriptions, documents of
		// uncertain nature.
		'text' => [ 'title', 'description', 'language', 'additionalLanguages', 'otherLanguage', 'labelLanguage', 'authors', 'year', 'url', 'accessUrl', 'wikidataId' ],
		// A legal provision: the clause text is the payload (monolingual,
		// language inherited from the parent legislation — never set by the
		// client), with optional added translations. It carries NO title
		// field: the label is derived (reference code + parent label) and
		// there is no `language` field (inherited).
		'law' => [ 'referenceCode', 'content', 'translations', 'description', 'parent' ],
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
	 * Title is the ONLY universally required field: every other fact
	 * (authors, year, publisher, …) can genuinely be unknown (e.g. a text
	 * of unknown authorship). Child classes additionally require their
	 * parent — a part-of relation is structural, not bibliographic metadata.
	 *
	 * @return string[]
	 */
	public static function requiredOnCreate( string $classKey ): array {
		// A legal provision has no title field: its label is derived from
		// the reference code + the parent legislation, and the clause text
		// is the payload. Required: the reference code, the clause content
		// (a provision with no text is meaningless) and the parent.
		if ( $classKey === 'law' ) {
			return [ 'referenceCode', 'content', 'parent' ];
		}
		$required = [ 'title' ];
		if ( self::isChildClass( $classKey ) ) {
			$required[] = 'parent';
		}
		return $required;
	}
}
