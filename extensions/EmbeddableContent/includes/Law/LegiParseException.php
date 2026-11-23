<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Law;

use RuntimeException;

/**
 * A DILA LEGI XML document could not be parsed into a corpus row.
 *
 * Fatal for the document, non-fatal for the run: the importer logs the file,
 * counts it and continues (one malformed increment file must not abort a
 * baseline import).
 *
 * @license GPL-2.0-or-later
 */
final class LegiParseException extends RuntimeException {
}
