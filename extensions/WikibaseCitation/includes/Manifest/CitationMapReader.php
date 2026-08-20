<?php

declare( strict_types = 1 );

namespace WikibaseCitation\Manifest;

/**
 * Reads the citation map manifests (manifests/citation-property-map.json and
 * manifests/citation-type-map.json) into plain string maps.
 *
 * Pure PHP — no MediaWiki or Wikibase dependencies — so it is unit-testable
 * standalone. The manifests reference the seeded vocabulary by *label*; the
 * maintenance importer resolves those labels to entity ids and publishes the
 * resolved map as an admin-editable MediaWiki: page.
 *
 * @license GPL-2.0-or-later
 */
class CitationMapReader {

	/** CSL field names accepted in the property map (per CSL 1.0.2 spec, common subset). */
	public const CSL_FIELDS = [
		'accessed', 'archive', 'archive_location', 'archive-place', 'author', 'call-number',
		'chapter-number', 'citation-key', 'collection-title', 'container-title', 'container-title-short',
		'DOI', 'edition', 'editor', 'event', 'event-place', 'familyName', 'first-reference-note-number', 'genre',
		'givenName', 'ISBN', 'ISSN', 'issue', 'issued', 'journalAbbreviation', 'language', 'license', 'medium',
		'note', 'number', 'number-of-pages', 'number-of-volumes', 'original-date', 'original-publisher',
		'original-publisher-place', 'original-title', 'page', 'page-first', 'PMCID', 'PMID', 'publisher',
		'publisher-place', 'references', 'reviewed-genre', 'reviewed-title', 'scale', 'section', 'source',
		'status', 'title', 'title-short', 'URL', 'version', 'volume', 'volume-title', 'year-suffix',
	];

	/** CSL type names accepted in the type map (per CSL 1.0.2 spec). */
	public const CSL_TYPES = [
		'article', 'article-journal', 'article-magazine', 'article-newspaper', 'bill', 'book',
		'broadcast', 'chapter', 'classic', 'collection', 'dataset', 'document', 'entry',
		'entry-dictionary', 'entry-encyclopedia', 'event', 'figure', 'graphic', 'hearing',
		'interview', 'legal_case', 'legislation', 'manuscript', 'map', 'motion_picture',
		'musical_score', 'pamphlet', 'paper-conference', 'patent', 'performance', 'periodical',
		'personal_communication', 'post', 'post-weblog', 'regulation', 'report', 'review',
		'review-book', 'software', 'song', 'speech', 'standard', 'thesis', 'treaty', 'webpage',
	];

	/**
	 * Reads the CSL field => property label map.
	 *
	 * @param string $path
	 *
	 * @return array<string,string>
	 * @throws CitationMapException
	 */
	public function readPropertyMap( string $path ): array {
		$data = $this->readJsonObject( $path );
		foreach ( $data as $field => $label ) {
			if ( !in_array( $field, self::CSL_FIELDS, true ) ) {
				throw new CitationMapException(
					sprintf( '%s: unknown CSL field "%s"', $path, $field )
				);
			}
			if ( trim( (string)$label ) === '' ) {
				throw new CitationMapException(
					sprintf( '%s: empty property label for CSL field "%s"', $path, $field )
				);
			}
		}
		return $data;
	}

	/**
	 * Reads the class label => CSL type map.
	 *
	 * @param string $path
	 *
	 * @return array<string,string>
	 * @throws CitationMapException
	 */
	public function readTypeMap( string $path ): array {
		$data = $this->readJsonObject( $path );
		foreach ( $data as $classLabel => $type ) {
			if ( trim( (string)$classLabel ) === '' ) {
				throw new CitationMapException( sprintf( '%s: empty class label', $path ) );
			}
			if ( !in_array( $type, self::CSL_TYPES, true ) ) {
				throw new CitationMapException(
					sprintf( '%s: unknown CSL type "%s" for class "%s"', $path, $type, $classLabel )
				);
			}
		}
		return $data;
	}

	/**
	 * @param string $path
	 *
	 * @return array<string,string>
	 * @throws CitationMapException
	 */
	private function readJsonObject( string $path ): array {
		$contents = @file_get_contents( $path );
		if ( $contents === false ) {
			throw new CitationMapException( sprintf( 'cannot open manifest "%s"', $path ) );
		}

		$data = json_decode( $contents, true );
		if ( !is_array( $data ) || array_is_list( $data ) ) {
			throw new CitationMapException(
				sprintf( '%s: not a JSON object with at least one entry', $path )
			);
		}
		foreach ( $data as $key => $value ) {
			if ( !is_string( $key ) || !is_string( $value ) ) {
				throw new CitationMapException(
					sprintf( '%s: entries must be string => string pairs', $path )
				);
			}
		}
		return $data;
	}
}
