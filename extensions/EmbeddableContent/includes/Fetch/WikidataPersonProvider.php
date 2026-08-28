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
				description: isset( $row['description'] ) ? (string)$row['description'] : null,
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
	 * VIAF lookup via the Wikidata hub. Note: a VIAF id can map to several
	 * Wikidata items — the SPARQL lookup returns the FIRST match only (same
	 * LIMIT-1 contract as byOrcid); the review step lets the author correct
	 * the pick.
	 */
	public function byViaf( string $viaf ): ?PersonRecord {
		$qid = $this->core->findItemByExternalId( 'P214', $viaf );
		return $qid === null ? null : $this->byWikidataId( $qid );
	}

	/** ISNI lookup via the Wikidata hub (ISNI is 1:1 with Wikidata items). */
	public function byIsni( string $isni ): ?PersonRecord {
		$qid = $this->core->findItemByExternalId( 'P213', $isni );
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
			$this->core->itemValueIds( $harvest['claims'], [ 'P735', 'P734', 'P19', 'P20' ] )
		);
		return new PersonRecord(
			label: $harvest['label'],
			description: $harvest['description'],
			givenName: $this->core->itemLabel( $harvest['claims'], 'P735', $itemLabels ),
			familyName: $this->core->itemLabel( $harvest['claims'], 'P734', $itemLabels ),
			orcid: $this->core->stringValue( $harvest['claims'], 'P496' ),
			viafId: $this->core->stringValue( $harvest['claims'], 'P214' ),
			isni: $this->core->stringValue( $harvest['claims'], 'P213' ),
			openalexId: $this->core->stringValue( $harvest['claims'], 'P5092' ),
			dateOfBirth: $this->core->timeValue( $harvest['claims'], 'P569' ),
			// The place LABEL, not the Wikidata QID: the review form matches
			// the label against the instance's OWN items (exact → fuzzy, with
			// user confirmation) before writing a local item reference. The
			// raw QID was previously dropped into the local-item combobox
			// and written as a LOCAL statement — a wrong/misleading link.
			// The label is already fetched by the resolveItemLabels batch
			// above (P19/P20 were already in the set), so no new request.
			placeOfBirth: $this->core->itemLabel( $harvest['claims'], 'P19', $itemLabels ),
			dateOfDeath: $this->core->timeValue( $harvest['claims'], 'P570' ),
			placeOfDeath: $this->core->itemLabel( $harvest['claims'], 'P20', $itemLabels ),
			wikidataId: $qid,
			appearsInIds: $this->core->itemValueIds( $harvest['claims'], [ 'P1441' ] ),
			provider: 'wikidata',
			providerId: $qid,
			enwikiTitle: $this->core->enwikiTitle( $harvest['sitelinks'] )
		);
	}
}
