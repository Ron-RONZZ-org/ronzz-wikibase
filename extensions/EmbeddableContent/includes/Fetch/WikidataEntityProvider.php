<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Wikidata entity provider (hub) for Special:AddCollective: organizations,
 * companies, bands, collectives. Class hints (P31) harvested on pick feed the
 * free-search class picker.
 *
 * @license GPL-2.0-or-later
 */
class WikidataEntityProvider implements EntityProvider {

	private WikidataCore $core;

	public function __construct( HttpClientInterface $http, float $timeout = 10.0 ) {
		$this->core = new WikidataCore( $http, $timeout );
	}

	public function searchByName( string $name ): array {
		$out = [];
		foreach ( $this->core->searchRaw( $name ) as $row ) {
			$out[] = new EntityRecord(
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
	 * Full harvest from a Wikidata QID, incl. instance-of class hints.
	 */
	public function byWikidataId( string $qid ): ?EntityRecord {
		$harvest = $this->core->harvestRaw( $qid );
		if ( $harvest === null ) {
			return null;
		}
		return new EntityRecord(
			label: $harvest['label'],
			description: $harvest['description'],
			wikidataId: $qid,
			classWikidataIds: $this->core->itemValueIds( $harvest['claims'], [ 'P31' ] ),
			provider: 'wikidata',
			providerId: $qid
		);
	}
}
