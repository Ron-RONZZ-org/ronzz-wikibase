<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\CSVTable;

/**
 * Pure CSV → row-array parser for the CSVTable media handler.
 *
 * No MediaWiki dependency — unit-testable standalone. The parser returns RAW
 * cell strings; the caller escapes them before rendering (the XSS boundary
 * lives in CSVTableOutput, not here).
 *
 * @license GPL-2.0-or-later
 */
final class CSVTableParser {

	public const DEFAULT_MAX_ROWS = 500;
	public const DEFAULT_MAX_COLS = 50;

	/** Delimiter candidates tried when the configured delimiter is "auto". */
	private const DELIMITER_CANDIDATES = [ ',', ';', "\t", '|' ];

	/**
	 * Parse CSV text into rows.
	 *
	 * @param string $data raw CSV text (UTF-8; a leading BOM is stripped)
	 * @param string $delimiter a literal delimiter, or "auto" to detect one
	 * @param bool $firstRowHeader whether row 0 is a header row
	 * @param int $maxRows abort past this many rows (CSVTableException)
	 * @param int $maxCols abort past this many columns (CSVTableException)
	 * @return array{header: bool, rows: array<int, array<int, string>>}
	 * @throws CSVTableException on a row/column cap breach
	 */
	public static function parse(
		string $data,
		string $delimiter = 'auto',
		bool $firstRowHeader = true,
		int $maxRows = self::DEFAULT_MAX_ROWS,
		int $maxCols = self::DEFAULT_MAX_COLS
	): array {
		// Strip a UTF-8 BOM (Excel writes one) — otherwise it becomes part of
		// the first header cell.
		if ( str_starts_with( $data, "\xEF\xBB\xBF" ) ) {
			$data = substr( $data, 3 );
		}

		$delimiter = self::detectDelimiter( $data, $delimiter );

		$stream = fopen( 'php://temp', 'r+' );
		if ( $stream === false ) {
			throw new CSVTableException( 'unreadable' );
		}
		fwrite( $stream, $data );
		rewind( $stream );

		$rows = [];
		// RFC 4180 quoting: '"' enclosure, no backslash escape ('' disables
		// the legacy escape so a literal backslash stays literal).
		while ( ( $fields = fgetcsv( $stream, 0, $delimiter, '"', '' ) ) !== false ) {
			// A blank line yields [null]; skip it.
			if ( $fields === [ null ] ) {
				continue;
			}
			if ( count( $fields ) > $maxCols ) {
				fclose( $stream );
				throw new CSVTableException( 'too-many-columns' );
			}
			$rows[] = array_map( static fn ( $field ): string => (string)$field, $fields );
			if ( count( $rows ) > $maxRows ) {
				fclose( $stream );
				throw new CSVTableException( 'too-many-rows' );
			}
		}
		fclose( $stream );

		return [
			'header' => $firstRowHeader && $rows !== [],
			'rows' => $rows,
		];
	}

	/**
	 * Resolve the delimiter: a configured literal wins, otherwise pick the
	 * candidate with the most occurrences on the first non-empty line
	 * (tie-break: comma, semicolon, tab, pipe).
	 */
	public static function detectDelimiter( string $data, string $configured ): string {
		if ( $configured !== 'auto' && $configured !== '' ) {
			return $configured;
		}
		foreach ( preg_split( '/\r\n|\n|\r/', $data ) ?: [] as $line ) {
			if ( trim( $line ) === '' ) {
				continue;
			}
			$best = ',';
			$bestCount = 0;
			foreach ( self::DELIMITER_CANDIDATES as $candidate ) {
				$count = substr_count( $line, $candidate );
				if ( $count > $bestCount ) {
					$bestCount = $count;
					$best = $candidate;
				}
			}
			return $best;
		}
		return ',';
	}
}
