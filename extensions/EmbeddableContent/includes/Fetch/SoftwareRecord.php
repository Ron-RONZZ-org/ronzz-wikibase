<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Normalized FOSS software record from an external authority (issue #26).
 * Field names follow the #7 field contract; Wikidata and GitHub agree on
 * them (label/name, description, website/homepage, repository, developer,
 * license, operating system, programming language, latest version).
 *
 * @license GPL-2.0-or-later
 */
final class SoftwareRecord {

	/**
	 * @param string[] $classWikidataIds instance-of class QIDs from the authority
	 */
	public function __construct(
		public readonly string $label,
		public readonly ?string $description = null,
		public readonly ?string $wikidataId = null,
		public readonly ?string $githubFullName = null,
		public readonly ?string $website = null,
		public readonly ?string $sourceRepository = null,
		public readonly ?string $developer = null,
		public readonly ?string $developerWikidataId = null,
		public readonly ?string $license = null,
		public readonly ?string $licenseWikidataId = null,
		public readonly ?string $operatingSystem = null,
		public readonly ?string $programmingLanguage = null,
		public readonly ?string $programmingLanguageWikidataId = null,
		public readonly ?string $latestVersion = null,
		public readonly ?string $userInterface = null,
		public readonly array $classWikidataIds = [],
		public readonly string $provider = '',
		public readonly ?string $providerId = null
	) {
	}
}
