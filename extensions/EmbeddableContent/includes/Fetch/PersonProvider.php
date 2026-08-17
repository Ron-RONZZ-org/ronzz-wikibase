<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Provider for person lookups (Special:AddPerson).
 *
 * Implementations are plain PHP + HttpClientInterface — no MediaWiki or
 * Wikibase dependency — so they are unit-testable standalone with a mocked
 * HTTP client.
 *
 * Note: the Wikidata-hub harvest (full record from a QID) is NOT part of
 * this interface — it is a Wikidata-specific capability exposed by
 * WikidataPersonProvider::byWikidataId().
 *
 * @license GPL-2.0-or-later
 */
interface PersonProvider {

	/**
	 * @param string $name free-text name search
	 * @return PersonRecord[]
	 */
	public function searchByName( string $name ): array;

	public function byOrcid( string $orcid ): ?PersonRecord;
}
