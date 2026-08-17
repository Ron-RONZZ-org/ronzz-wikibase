<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Wikidata person provider (hub). Search via wbsearchentities; full harvest
 * (given/family name, ORCID, VIAF, ISNI) via wbgetentities on pick.
 *
 * @license GPL-2.0-or-later
 */
class WikidataPersonProvider implements PersonProvider {

	private WikidataCore $core;

	public function __construct( HttpClientInterface $http, float $timeout = 10.0 ) {
		$this->core = new WikidataCore( $http, $timeout );
	}

	public function searchByName( string $name ): array {
		$out = [];
		foreach ( $this->core->searchRaw( $name ) as $row ) {
			$out[] = new PersonRecord(
				label: (string)( $row['label'] ?? $row['id'] ),
				wikidataId: $row['id'],
				provider: 'wikidata',
				providerId: $row['id']
			);
		}
		return $out;
	}

	public function byOrcid( string $orcid ): ?PersonRecord {
		$qid = $this->core->findItemByExternalId( 'P496', $orcid );
		return $qid === null ? null : $this->byWikidataId( $qid );
	}

	/**
	 * Full harvest from a Wikidata QID (the hub's pick step).
	 */
	public function byWikidataId( string $qid ): ?PersonRecord {
		$harvest = $this->core->harvestRaw( $qid );
		if ( $harvest === null ) {
			return null;
		}
		$itemLabels = $this->core->resolveItemLabels(
			$this->core->itemValueIds( $harvest['claims'], [ 'P735', 'P734' ] )
		);
		return new PersonRecord(
			label: $harvest['label'],
			givenName: $this->core->itemLabel( $harvest['claims'], 'P735', $itemLabels ),
			familyName: $this->core->itemLabel( $harvest['claims'], 'P734', $itemLabels ),
			orcid: $this->core->stringValue( $harvest['claims'], 'P496' ),
			viafId: $this->core->stringValue( $harvest['claims'], 'P214' ),
			isni: $this->core->stringValue( $harvest['claims'], 'P213' ),
			wikidataId: $qid,
			provider: 'wikidata',
			providerId: $qid
		);
	}
}
