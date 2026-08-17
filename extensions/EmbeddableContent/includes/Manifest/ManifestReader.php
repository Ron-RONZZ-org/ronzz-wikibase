<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Manifest;

/**
 * Reads vocabulary manifests (CSV) into typed row objects.
 *
 * Pure PHP — no MediaWiki or Wikibase dependencies — so it is unit-testable
 * standalone and shared by all maintenance importers.
 *
 * Manifest columns (header row, order-independent):
 * - `label.<lang>` and `description.<lang>`: one column pair per language;
 *   the declared language sets must be identical.
 * - properties.csv additionally: `datatype` (required), `align.uri`,
 *   `align.wikidata`, `formatter.url` (optional — external-id properties only).
 * - classes.csv: `align.uri`, `align.wikidata` (optional).
 * - languages.csv: `lexer` (required), `wikidata_qid` (optional).
 *
 * @license GPL-2.0-or-later
 */
class ManifestReader {

	/** Wikibase datatype ids accepted by the property manifest. */
	public const PROPERTY_DATATYPES = [
		'wikibase-item',
		'monolingualtext',
		'string',
		'url',
		'time',
		'external-id',
	];

	/**
	 * @param string $path
	 *
	 * @return PropertyManifestRow[]
	 * @throws ManifestException
	 */
	public function readProperties( string $path ): array {
		$manifest = $this->readRows( $path );
		$seenLabels = [];

		$rows = [];
		foreach ( $manifest->rows as $line => $row ) {
			$this->assertColumn( $row, $line, 'datatype' );
			$datatype = trim( (string)$row['datatype'] );
			if ( !in_array( $datatype, self::PROPERTY_DATATYPES, true ) ) {
				throw new ManifestException(
					sprintf(
						'%s line %d: invalid datatype "%s" (allowed: %s)',
						$path, $line, $datatype, implode( ', ', self::PROPERTY_DATATYPES )
					)
				);
			}
			$labels = $this->extractTerms( $row, $manifest->languages, 'label', $path, $line );
			$descriptions = $this->extractTerms( $row, $manifest->languages, 'description', $path, $line );
			$this->assertUniqueLabels( $labels, $seenLabels, $path, $line );

			$rows[] = new PropertyManifestRow(
				$labels,
				$descriptions,
				$datatype,
				$this->optionalUrl( $row, 'align.uri', $path, $line ),
				$this->optionalUrl( $row, 'align.wikidata', $path, $line ),
				$this->optionalUrl( $row, 'formatter.url', $path, $line )
			);
		}

		return $rows;
	}

	/**
	 * @param string $path
	 *
	 * @return ClassManifestRow[]
	 * @throws ManifestException
	 */
	public function readClasses( string $path ): array {
		$manifest = $this->readRows( $path );
		$seenLabels = [];

		$rows = [];
		foreach ( $manifest->rows as $line => $row ) {
			$labels = $this->extractTerms( $row, $manifest->languages, 'label', $path, $line );
			$descriptions = $this->extractTerms( $row, $manifest->languages, 'description', $path, $line );
			$this->assertUniqueLabels( $labels, $seenLabels, $path, $line );

			$rows[] = new ClassManifestRow(
				$labels,
				$descriptions,
				$this->optionalUrl( $row, 'align.uri', $path, $line ),
				$this->optionalUrl( $row, 'align.wikidata', $path, $line )
			);
		}

		return $rows;
	}

	/**
	 * @param string $path
	 *
	 * @return LanguageManifestRow[]
	 * @throws ManifestException
	 */
	public function readLanguages( string $path ): array {
		$manifest = $this->readRows( $path );
		$seenLexers = [];
		$seenLabels = [];

		$rows = [];
		foreach ( $manifest->rows as $line => $row ) {
			$this->assertColumn( $row, $line, 'lexer' );
			$lexer = trim( (string)$row['lexer'] );
			if ( $lexer === '' ) {
				throw new ManifestException( sprintf( '%s line %d: empty lexer name', $path, $line ) );
			}
			if ( isset( $seenLexers[$lexer] ) ) {
				throw new ManifestException(
					sprintf( '%s line %d: duplicate lexer "%s" (first seen on line %d)', $path, $line, $lexer, $seenLexers[$lexer] )
				);
			}
			$seenLexers[$lexer] = $line;

			$labels = $this->extractTerms( $row, $manifest->languages, 'label', $path, $line );
			$descriptions = $this->extractTerms( $row, $manifest->languages, 'description', $path, $line );
			$this->assertUniqueLabels( $labels, $seenLabels, $path, $line );

			$wikidataQid = null;
			$qid = trim( (string)( $row['wikidata_qid'] ?? '' ) );
			if ( $qid !== '' ) {
				if ( preg_match( '/^Q[1-9][0-9]*$/', $qid ) !== 1 ) {
					throw new ManifestException(
						sprintf( '%s line %d: invalid Wikidata Q-id "%s" (expected e.g. "Q9296")', $path, $line, $qid )
					);
				}
				$wikidataQid = $qid;
			}

			$rows[] = new LanguageManifestRow( $lexer, $labels, $descriptions, $wikidataQid );
		}

		return $rows;
	}

	/**
	 * @param string $path
	 *
	 * @return object{ languages: string[], rows: array<int,array<string,string>> }
	 * @throws ManifestException
	 */
	private function readRows( string $path ): object {
		$handle = @fopen( $path, 'rb' );
		if ( $handle === false ) {
			throw new ManifestException( sprintf( 'cannot open manifest "%s"', $path ) );
		}

		try {
			$header = fgetcsv( $handle, 0, ',', '"', '\\' );
			if ( $header === false || $header === null ) {
				throw new ManifestException( sprintf( '%s: missing header row', $path ) );
			}

			$columns = [];
			foreach ( $header as $column ) {
				// Strip a possible UTF-8 BOM from the first header cell.
				$column = preg_replace( '/^\xEF\xBB\xBF/', '', (string)$column );
				$columns[] = trim( $column );
			}

			$languages = $this->extractLanguages( $columns, $path );

			$rows = [];
			$line = 1; // header is line 1
			while ( ( $record = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
				$line++;
				if ( $record === null || ( count( $record ) === 1 && trim( (string)$record[0] ) === '' ) ) {
					continue; // blank line
				}
				if ( count( $record ) !== count( $columns ) ) {
					throw new ManifestException(
						sprintf( '%s line %d: expected %d columns, got %d', $path, $line, count( $columns ), count( $record ) )
					);
				}
				$row = [];
				foreach ( $columns as $index => $column ) {
					$row[$column] = $record[$index];
				}
				$rows[$line] = $row;
			}
		} finally {
			fclose( $handle );
		}

		return (object)[ 'languages' => $languages, 'rows' => $rows ];
	}

	/**
	 * @param string[] $columns
	 * @param string $path
	 *
	 * @return string[] language codes present in the manifest, in column order
	 * @throws ManifestException
	 */
	private function extractLanguages( array $columns, string $path ): array {
		$labelLanguages = [];
		$descriptionLanguages = [];
		foreach ( $columns as $column ) {
			if ( preg_match( '/^label\.([a-z-]+)$/', $column, $m ) === 1 ) {
				$labelLanguages[] = $m[1];
			} elseif ( preg_match( '/^description\.([a-z-]+)$/', $column, $m ) === 1 ) {
				$descriptionLanguages[] = $m[1];
			}
		}

		if ( $labelLanguages === [] ) {
			throw new ManifestException( sprintf( '%s: no label.<lang> column found', $path ) );
		}
		if ( $labelLanguages !== $descriptionLanguages ) {
			throw new ManifestException(
				sprintf(
					'%s: label and description language columns must match (labels: %s, descriptions: %s)',
					$path, implode( ',', $labelLanguages ), implode( ',', $descriptionLanguages )
				)
			);
		}

		return $labelLanguages;
	}

	/**
	 * Extracts and validates the label or description terms for all manifest languages.
	 *
	 * @param array<string,string> $row
	 * @param string[] $languages
	 * @param string $kind "label" or "description"
	 * @param string $path
	 * @param int $line
	 *
	 * @return array<string,string>
	 * @throws ManifestException
	 */
	private function extractTerms( array $row, array $languages, string $kind, string $path, int $line ): array {
		$terms = [];
		foreach ( $languages as $language ) {
			$value = trim( (string)( $row[$kind . '.' . $language] ?? '' ) );
			if ( $value === '' ) {
				throw new ManifestException(
					sprintf( '%s line %d: missing %s for language "%s"', $path, $line, $kind, $language )
				);
			}
			$terms[$language] = $value;
		}
		return $terms;
	}

	/**
	 * @param array<string,string> $labels
	 * @param array<string,array<string,int>> $seenLabels language => label => line
	 * @param string $path
	 * @param int $line
	 *
	 * @throws ManifestException
	 */
	private function assertUniqueLabels( array $labels, array &$seenLabels, string $path, int $line ): void {
		foreach ( $labels as $language => $label ) {
			if ( isset( $seenLabels[$language][$label] ) ) {
				throw new ManifestException(
					sprintf(
						'%s line %d: duplicate %s label "%s" (first seen on line %d)',
						$path, $line, $language, $label, $seenLabels[$language][$label]
					)
				);
			}
			$seenLabels[$language][$label] = $line;
		}
	}

	/**
	 * @param array<string,string> $row
	 * @param string $column
	 * @param string $path
	 * @param int $line
	 *
	 * @return string|null
	 * @throws ManifestException
	 */
	private function optionalUrl( array $row, string $column, string $path, int $line ): ?string {
		$value = trim( (string)( $row[$column] ?? '' ) );
		if ( $value === '' ) {
			return null;
		}
		if ( filter_var( $value, FILTER_VALIDATE_URL ) === false ) {
			throw new ManifestException( sprintf( '%s line %d: invalid URL in column "%s": "%s"', $path, $line, $column, $value ) );
		}
		return $value;
	}

	/**
	 * @param array<string,string> $row
	 * @param int $line
	 * @param string $column
	 *
	 * @throws ManifestException
	 */
	private function assertColumn( array $row, int $line, string $column ): void {
		if ( !array_key_exists( $column, $row ) ) {
			throw new ManifestException( sprintf( 'line %d: missing required column "%s"', $line, $column ) );
		}
	}
}
