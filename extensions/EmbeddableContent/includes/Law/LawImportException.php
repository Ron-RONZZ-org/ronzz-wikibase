<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Law;

use RuntimeException;

/**
 * A law-corpus import cannot start or cannot continue (missing directory,
 * missing schema). Per-document parse failures are counted and logged, not
 * raised as this exception.
 *
 * @license GPL-2.0-or-later
 */
final class LawImportException extends RuntimeException {
}
