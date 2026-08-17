<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Provider for generic entity lookups (Special:AddCollective).
 *
 * Note: the Wikidata-hub harvest (full record from a QID) is NOT part of
 * this interface — it is a Wikidata-specific capability exposed by
 * WikidataEntityProvider::byWikidataId().
 *
 * @license GPL-2.0-or-later
 */
interface EntityProvider {

	/**
	 * @param string $name free-text name search
	 * @return EntityRecord[]
	 */
	public function searchByName( string $name ): array;
}
