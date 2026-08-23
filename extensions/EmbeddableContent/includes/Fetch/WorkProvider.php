<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Provider for work (book / article / song / film / …) lookups
 * (Special:AddSource).
 *
 * Note: the Wikidata-hub harvest (full record from a QID) is NOT part of
 * this interface — it is a Wikidata-specific capability exposed by
 * WikidataWorkProvider::byWikidataId().
 *
 * @license GPL-2.0-or-later
 */
interface WorkProvider {

	/**
	 * @param string $title free-text title search
	 * @return WorkRecord[]
	 */
	public function searchByTitle( string $title ): array;

	/**
	 * Free-text author-name search, optionally narrowed by a title. Providers
	 * without an author filter return [] (the cascade skips them).
	 *
	 * @param string $author author name (e.g. "Albert Einstein")
	 * @param string $title optional title to narrow the search
	 * @return WorkRecord[]
	 */
	public function searchByAuthorName( string $author, string $title = '' ): array;

	/**
	 * Work search by Wikidata author ENTITY ids (Q-ids) — the semantic-entity
	 * counterpart of searchByAuthorName(). Wikidata is the hub for QID
	 * lookups; providers that cannot filter by Wikidata Q return [].
	 *
	 * @param string[] $qids Wikidata author entity ids (e.g. [ "Q42" ])
	 * @param string $title optional title to narrow the search
	 * @return WorkRecord[]
	 */
	public function searchByAuthorEntities( array $qids, string $title = '' ): array;

	public function byDoi( string $doi ): ?WorkRecord;

	public function byIsbn( string $isbn ): ?WorkRecord;

	/**
	 * Best-effort abstract + keywords for a DOI (the page-content fetch for
	 * scholarly articles). Providers without abstracts return [].
	 *
	 * @return array{abstract: ?string, keywords: ?string, source: ?string}
	 *   'abstract'/'keywords' null when absent; 'source' one of
	 *   'openalex'|'crossref' when content was returned
	 */
	public function abstractAndKeywordsByDoi( string $doi ): array;
}
