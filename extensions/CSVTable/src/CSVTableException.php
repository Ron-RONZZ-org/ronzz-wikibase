<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\CSVTable;

/**
 * Raised when a CSV file cannot be rendered as a table. The message is a
 * stable error code (e.g. "too-many-rows") the output maps to an i18n
 * message — never user-facing text.
 *
 * @license GPL-2.0-or-later
 */
class CSVTableException extends \RuntimeException {
}
