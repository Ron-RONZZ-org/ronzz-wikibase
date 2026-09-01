<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\EmbeddableContentConfig;

/**
 * Assembles the duplicate-check statement pairs from a creation record —
 * shared by the browser Add* forms and the entity-mode API modules so the
 * guard can never drift between the two surfaces.
 *
 * A "pair" is (property id => exact value) for the signals the duplication
 * guard is defined on (see DuplicateFinder): authority external ids
 * (Wikidata/OpenAlex/ORCID/DOI/ISBN/VIAF/ISNI/YouTube) and web URLs
 * (official website, source repository, documentation URL, access URL).
 * Only pairs whose property id resolves in the config AND whose value is
 * present in the record are emitted — instance-specific availability is
 * respected (absent config keys are omitted).
 *
 * @license GPL-2.0-or-later
 */
final class DuplicateGuard {

	/**
	 * [config accessor, config key, record field] — the union of every
	 * id/URL signal the Add* vocabularies can write. The accessor returns
	 * the property-id map for the section; the key names a config key; the
	 * field names the creation-record key holding the value.
	 */
	private const PAIRS = [
		// Authority external ids (externalIds config section).
		[ 'externalIdPropertyIds', 'wikidata', 'wikidataId' ],
		[ 'externalIdPropertyIds', 'orcid', 'orcid' ],
		[ 'externalIdPropertyIds', 'viaf', 'viafId' ],
		[ 'externalIdPropertyIds', 'isni', 'isni' ],
		[ 'externalIdPropertyIds', 'doi', 'doi' ],
		[ 'externalIdPropertyIds', 'isbn', 'isbn' ],
		[ 'externalIdPropertyIds', 'openalex', 'openalexWorkId' ],
		[ 'externalIdPropertyIds', 'openalexAuthor', 'openalexAuthorId' ],
		[ 'externalIdPropertyIds', 'pubmed', 'pubmedId' ],
		// YouTube ids (sourceProperties section).
		[ 'sourcePropertyIds', 'youtubeChannelId', 'youtubeChannelId' ],
		[ 'sourcePropertyIds', 'youtubeVideoId', 'youtubeVideoId' ],
		// Web URLs (the per-kind URL properties, P856/P1325-aligned).
		[ 'personPropertyIds', 'officialWebsite', 'officialWebsite' ],
		[ 'collectivePropertyIds', 'officialWebsite', 'officialWebsite' ],
		[ 'fossPropertyIds', 'officialWebsite', 'officialWebsite' ],
		[ 'fossPropertyIds', 'sourceRepository', 'sourceCodeRepository' ],
		[ 'fossPropertyIds', 'documentationUrl', 'documentationUrl' ],
		[ 'sourcePropertyIds', 'url', 'url' ],
		[ 'sourcePropertyIds', 'accessUrl', 'accessUrl' ],
	];

	/**
	 * The (property id => exact value) pairs a record would write, for the
	 * duplicate check. The record may use either the browser-form keys or
	 * the API keys — the URL fields are matched leniently across both.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,string> property id => value
	 */
	public static function pairsFor( EmbeddableContentConfig $config, array $record ): array {
		$out = [];
		foreach ( self::PAIRS as [ $accessor, $key, $field ] ) {
			$propertyId = $config->$accessor()[$key] ?? null;
			if ( $propertyId === null || !is_string( $propertyId ) ) {
				continue;
			}
			$value = $record[$field] ?? '';
			if ( !is_string( $value ) || trim( $value ) === '' ) {
				continue;
			}
			$out[$propertyId] = trim( $value );
		}
		return $out;
	}

}
