<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\CSVTable;

use MediaWiki\FileRepo\File\File;
use MediaWiki\Html\Html;
use MediaWiki\Media\MediaTransformOutput;

/**
 * Renders a CSV file as a `wikitable`.
 *
 * The CSV is read from the file's local path at render time (the file
 * revision is a parser-cache dependency through the `[[File:…]]` usage, so
 * the table follows the current revision). Every cell is HTML-escaped — this
 * is the XSS boundary. Oversized / unreadable / over-capped files degrade to
 * an error notice, never a broken page.
 *
 * @license GPL-2.0-or-later
 */
class CSVTableOutput extends MediaTransformOutput {

	private string $delimiter;
	private bool $firstRowHeader;
	private int $maxBytes;
	private int $maxRows;
	private int $maxCols;

	public function __construct(
		File $file,
		string $delimiter,
		bool $firstRowHeader,
		int $maxBytes,
		int $maxRows,
		int $maxCols
	) {
		$this->file = $file;
		$this->url = $file->getFullUrl();
		$this->delimiter = $delimiter;
		$this->firstRowHeader = $firstRowHeader;
		$this->maxBytes = $maxBytes;
		$this->maxRows = $maxRows;
		$this->maxCols = $maxCols;
	}

	/** @inheritDoc */
	public function toHtml( $options = [] ) {
		$path = method_exists( $this->file, 'getLocalRefPath' ) ? $this->file->getLocalRefPath() : null;
		if ( !is_string( $path ) || $path === '' || !is_readable( $path ) ) {
			return $this->fallbackLink();
		}

		// Read at most one byte past the cap so an oversized file is detected
		// without loading it whole.
		$data = file_get_contents( $path, false, null, 0, $this->maxBytes + 1 );
		if ( $data === false ) {
			return $this->errorNotice( 'csvtable-error-unreadable' );
		}
		if ( strlen( $data ) > $this->maxBytes ) {
			return $this->errorNotice( 'csvtable-error-too-large', $this->maxBytes );
		}

		try {
			$parsed = CSVTableParser::parse(
				$data,
				$this->delimiter,
				$this->firstRowHeader,
				$this->maxRows,
				$this->maxCols
			);
		} catch ( CSVTableException $e ) {
			return match ( $e->getMessage() ) {
				'too-many-rows' => $this->errorNotice( 'csvtable-error-too-many-rows', $this->maxRows ),
				'too-many-columns' => $this->errorNotice( 'csvtable-error-too-many-columns', $this->maxCols ),
				default => $this->errorNotice( 'csvtable-error-unreadable' ),
			};
		}

		return self::renderTable( $parsed['rows'], $parsed['header'] );
	}

	/**
	 * @param array<int, array<int, string>> $rows
	 */
	private static function renderTable( array $rows, bool $header ): string {
		$html = '<table class="wikitable csv-table">';
		foreach ( $rows as $index => $row ) {
			$cell = ( $header && $index === 0 ) ? 'th' : 'td';
			$html .= '<tr>';
			foreach ( $row as $value ) {
				$html .= '<' . $cell . '>' . htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) . '</' . $cell . '>';
			}
			$html .= '</tr>';
		}
		return $html . '</table>';
	}

	private function errorNotice( string $messageKey, ?int $limit = null ): string {
		$message = $limit !== null ? wfMessage( $messageKey, $limit ) : wfMessage( $messageKey );
		return Html::rawElement( 'span', [ 'class' => 'csv-table-error' ], $message->escaped() );
	}

	private function fallbackLink(): string {
		return Html::element(
			'a',
			[ 'href' => $this->file->getFullUrl(), 'class' => 'csv-table-fallback' ],
			wfMessage( 'csvtable-download' )->text()
		);
	}
}
