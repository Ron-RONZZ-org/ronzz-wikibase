<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Normalized person record from any provider (label + authority IDs).
 * Field names follow the #7 field contract and the #8 curated bundle.
 *
 * @license GPL-2.0-or-later
 */
	final class PersonRecord {

	/**
	 * @param string[] $appearsInIds Wikidata Q-ids of the works this
	 *   character appears in (P1441, harvested for fictional characters)
	 */
	public function __construct(
		public readonly string $label,
		public readonly ?string $description = null,
		public readonly ?string $givenName = null,
		public readonly ?string $familyName = null,
		public readonly ?string $orcid = null,
		public readonly ?string $viafId = null,
		public readonly ?string $isni = null,
		public readonly ?string $openalexId = null,
		public readonly ?string $dateOfBirth = null,
		public readonly ?string $placeOfBirth = null,
		public readonly ?string $dateOfDeath = null,
		public readonly ?string $placeOfDeath = null,
		public readonly ?string $wikidataId = null,
		public readonly array $appearsInIds = [],
		public readonly string $provider = '',
		public readonly ?string $providerId = null,
		public readonly ?string $enwikiTitle = null
	) {
	}
}
