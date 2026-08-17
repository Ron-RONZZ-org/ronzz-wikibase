<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Provider for work (book / article / song / film / …) lookups
 * (Special:AddSource).
 *
 * Note: the Wikidata-hub harvest (full record from a QID) is NOT part of
 * this interface — it is a Wikidata-specific capability exposed by
 * WikidataWorkProvider::byWikidataId().
 *
 * @license GPL-2.0-or-later
 */
interface WorkProvider {

	/**
	 * @param string $title free-text title search
	 * @return WorkRecord[]
	 */
	public function searchByTitle( string $title ): array;

	public function byDoi( string $doi ): ?WorkRecord;

	public function byIsbn( string $isbn ): ?WorkRecord;
}
