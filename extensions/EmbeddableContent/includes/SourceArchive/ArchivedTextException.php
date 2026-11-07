<?php

declare( strict_types = 1 );

namespace EmbeddableContent\SourceArchive;

/**
 * Raised when the automatic page-text archive cannot be produced (the
 * HTML → wikitext conversion failed, or the converter returned nothing).
 *
 * The archive is best-effort: the caller logs this failure and keeps the
 * item and its classic Source: page — never fail the user's creation.
 *
 * @license GPL-2.0-or-later
 */
class ArchivedTextException extends \RuntimeException {
}
