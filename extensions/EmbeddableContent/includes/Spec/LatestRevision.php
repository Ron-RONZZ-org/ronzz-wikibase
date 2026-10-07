<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use Wikibase\DataModel\Entity\EntityId;
use Wikibase\Lib\Store\EntityRevisionLookup;

/**
 * Resolver for an entity's latest revision id.
 *
 * Wikibase's `EntityRevisionLookup::getLatestRevisionId()` returns a
 * `LatestRevisionIdResult` MONAD, not an int (a redirect carries the target's
 * revision id, a missing entity carries none). Passing that object where a
 * revision id is expected (e.g. `ParserOutput::addTemplate()`'s untyped
 * `$revId`) silently stores an object — the parser-cache dependency then
 * records a useless revision id. This helper maps the monad to an int, with
 * 0 for a missing entity (the same sentinel the callers used via `?? 0`).
 *
 * @license GPL-2.0-or-later
 */
final class LatestRevision {

	/**
	 * The latest revision id of an entity (0 when it does not exist; a
	 * redirect resolves to the redirect target's revision id).
	 */
	public static function id( EntityRevisionLookup $lookup, EntityId $entityId ): int {
		return $lookup->getLatestRevisionId( $entityId )
			->onConcreteRevision( static fn ( int $revId ): int => $revId )
			->onRedirect( static fn ( int $revId ): int => $revId )
			->onNonexistentEntity( static fn (): int => 0 )
			->map();
	}
}
