<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

use RuntimeException;

/**
 * Base exception for citation rendering failures (issue #24). Specific
 * subclasses let each surface (API module vs parser functions) map the
 * failure to its own error presentation.
 *
 * @license GPL-2.0-or-later
 */
class CitationException extends RuntimeException {
}
