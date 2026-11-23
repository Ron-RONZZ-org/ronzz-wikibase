<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Law;

use RuntimeException;

/**
 * A source XML document could not be parsed. Non-fatal at the importer
 * level: the file is counted as an error and the walk continues.
 *
 * @license GPL-2.0-or-later
 */
class LawParseException extends RuntimeException {
}
