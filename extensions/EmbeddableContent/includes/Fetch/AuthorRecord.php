<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * One author/creator carried by a WorkRecord. The authority record may know
 * only a display name (Open Library) or a full set of identifiers
 * (OpenAlex/Crossref/Wikidata); the AddSource flow needs local item IDs, so
 * the authority-search layer resolves these records against the local
 * agent-class items before suggesting add fields.
 *
 * @license GPL-2.0-or-later
 */
final class AuthorRecord {

	public function __construct(
		public readonly string $label,
		public readonly ?string $givenName = null,
		public readonly ?string $familyName = null,
		public readonly ?string $orcid = null,
		public readonly ?string $viafId = null,
		public readonly ?string $isni = null,
		public readonly ?string $openalexId = null,
		public readonly ?string $wikidataId = null,
		public readonly string $provider = '',
		public readonly ?string $providerId = null
	) {
	}
}
