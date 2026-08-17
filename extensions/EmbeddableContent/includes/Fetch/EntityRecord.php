<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Normalized generic entity record (non-person agents, works before
 * classification, …) with class hints harvested from the authority.
 *
 * @license GPL-2.0-or-later
 */
final class EntityRecord {

	/**
	 * @param string[] $classWikidataIds instance-of class QIDs from the authority
	 */
	public function __construct(
		public readonly string $label,
		public readonly ?string $description = null,
		public readonly ?string $wikidataId = null,
		public readonly array $classWikidataIds = [],
		public readonly string $provider = '',
		public readonly ?string $providerId = null
	) {
	}
}
