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

	public function __construct(
		public readonly string $label,
		public readonly ?string $description = null,
		public readonly ?string $givenName = null,
		public readonly ?string $familyName = null,
		public readonly ?string $orcid = null,
		public readonly ?string $viafId = null,
		public readonly ?string $isni = null,
		public readonly ?string $wikidataId = null,
		public readonly string $provider = '',
		public readonly ?string $providerId = null
	) {
	}
}
