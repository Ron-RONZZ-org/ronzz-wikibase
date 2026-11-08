<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Page-size rules for Special:EntityIndex. Pure so the clamping contract is
 * unit-tested without MediaWiki; the special page supplies the request value
 * and the DB query.
 *
 * @license GPL-2.0-or-later
 */
final class EntityIndexPaging {

	/** Entries per page when `limit` is absent (or <= 0). */
	public const DEFAULT_LIMIT = 100;

	/** Smallest accepted `limit`. */
	public const MIN_LIMIT = 10;

	/** Largest accepted `limit`. */
	public const MAX_LIMIT = 200;

	/**
	 * Clamps a requested page size to the supported 10–200 range; a
	 * non-positive request falls back to the default.
	 */
	public static function clampLimit( int $requested ): int {
		if ( $requested <= 0 ) {
			return self::DEFAULT_LIMIT;
		}
		return max( self::MIN_LIMIT, min( self::MAX_LIMIT, $requested ) );
	}
}
