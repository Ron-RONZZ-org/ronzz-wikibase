<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Normalized work record (book / article / song / film / …) from any provider.
 * Field names follow the #7 field contract and the #8 curated bundle.
 *
 * @license GPL-2.0-or-later
 */
final class WorkRecord {

	/**
	 * @param string[] $classWikidataIds instance-of class QIDs from the authority
	 */
	public function __construct(
		public readonly string $title,
		public readonly ?string $description = null,
		public readonly ?string $containerTitle = null,
		public readonly ?string $publisher = null,
		public readonly ?string $volume = null,
		public readonly ?string $issue = null,
		public readonly ?string $pages = null,
		public readonly ?string $doi = null,
		public readonly ?string $isbn = null,
		public readonly ?string $openalexId = null,
		public readonly ?string $pubmedId = null,
		public readonly ?string $wikidataId = null,
		public readonly ?int $issuedYear = null,
		public readonly array $classWikidataIds = [],
		public readonly string $provider = '',
		public readonly ?string $providerId = null
	) {
	}
}
