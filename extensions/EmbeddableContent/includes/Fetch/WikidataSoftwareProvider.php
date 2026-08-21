<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Wikidata software provider (hub) for Special:AddSoftware. Search via
 * wbsearchentities; full harvest (official website P856, source code
 * repository P1324, developer P178, license P275, operating system P306,
 * programming language P277, latest version P348, user interface P1262,
 * instance-of P31 class hints) via wbgetentities on pick.
 *
 * Item-typed values (developer, license, OS, language, UI) are resolved to
 * their Wikidata labels so the review form can display them; whether a local
 * item exists is decided at form-render time by the caller.
 *
 * @license GPL-2.0-or-later
 */
class WikidataSoftwareProvider implements SoftwareProvider {

	private const ITEM_TYPED_PROPERTIES = [ 'P178', 'P275', 'P306', 'P277', 'P1262' ];

	private WikidataCore $core;

	public function __construct( HttpClientInterface $http, float $timeout = 10.0 ) {
		$this->core = new WikidataCore( $http, $timeout );
	}

	public function searchByName( string $name ): array {
		$out = [];
		foreach ( $this->core->searchRaw( $name ) as $row ) {
			$out[] = new SoftwareRecord(
				label: (string)( $row['label'] ?? $row['id'] ),
				description: isset( $row['description'] ) ? (string)$row['description'] : null,
				wikidataId: $row['id'],
				provider: 'wikidata',
				providerId: $row['id']
			);
		}
		return $out;
	}

	/**
	 * Full harvest from a Wikidata QID (the hub's pick step).
	 */
	public function byWikidataId( string $qid ): ?SoftwareRecord {
		$harvest = $this->core->harvestRaw( $qid );
		if ( $harvest === null ) {
			return null;
		}
		$itemLabels = $this->core->resolveItemLabels(
			$this->core->itemValueIds( $harvest['claims'], self::ITEM_TYPED_PROPERTIES )
		);
		$operatingSystems = $this->core->itemValueIds( $harvest['claims'], [ 'P306' ] );
		return new SoftwareRecord(
			label: $harvest['label'],
			description: $harvest['description'],
			wikidataId: $qid,
			website: $this->core->stringValue( $harvest['claims'], 'P856' ),
			sourceRepository: $this->core->stringValue( $harvest['claims'], 'P1324' ),
			developer: $this->core->itemLabel( $harvest['claims'], 'P178', $itemLabels ),
			developerWikidataId: $this->firstItemValueId( $harvest['claims'], 'P178' ),
			license: $this->core->itemLabel( $harvest['claims'], 'P275', $itemLabels ),
			licenseWikidataId: $this->firstItemValueId( $harvest['claims'], 'P275' ),
			operatingSystem: $this->joinLabels( $operatingSystems, $itemLabels ),
			programmingLanguage: $this->core->itemLabel( $harvest['claims'], 'P277', $itemLabels ),
			programmingLanguageWikidataId: $this->firstItemValueId( $harvest['claims'], 'P277' ),
			latestVersion: $this->core->stringValue( $harvest['claims'], 'P348' ),
			userInterface: $this->core->itemLabel( $harvest['claims'], 'P1262', $itemLabels ),
			classWikidataIds: $this->core->itemValueIds( $harvest['claims'], [ 'P31' ] ),
			provider: 'wikidata',
			providerId: $qid
		);
	}

	/** @return string[] */
	private function firstItemValueId( array $claims, string $propertyId ): ?string {
		$ids = $this->core->itemValueIds( $claims, [ $propertyId ] );
		return $ids[0] ?? null;
	}

	/**
	 * @param string[] $qids
	 * @param array<string,string> $itemLabels
	 */
	private function joinLabels( array $qids, array $itemLabels ): ?string {
		$labels = [];
		foreach ( $qids as $qid ) {
			if ( isset( $itemLabels[$qid] ) ) {
				$labels[] = $itemLabels[$qid];
			}
		}
		return $labels === [] ? null : implode( ', ', $labels );
	}
}
