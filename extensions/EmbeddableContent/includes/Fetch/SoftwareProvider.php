<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Provider for FOSS software lookups (Special:AddSoftware, issue #26).
 *
 * Note: the Wikidata-hub harvest (full record from a QID) is NOT part of
 * this interface — it is a Wikidata-specific capability exposed by
 * WikidataSoftwareProvider::byWikidataId(); GitHub harvests happen through
 * GitHubSoftwareProvider::byFullName(). ProviderClient exposes both.
 *
 * @license GPL-2.0-or-later
 */
interface SoftwareProvider {

	/**
	 * @param string $name free-text software-name search
	 * @return SoftwareRecord[]
	 */
	public function searchByName( string $name ): array;
}
