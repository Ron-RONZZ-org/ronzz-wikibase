<?php

declare( strict_types = 1 );

/**
 * Pure-PHP stubs of MediaWiki-contract types used by the CitationEngine.
 *
 * The engine type-hints Wikibase\Lib\Store\EntityRevisionLookup (Wikibase
 * extension) and Wikimedia\ObjectCache\BagOStuff (MediaWiki core) — neither
 * exists in the composer-only unit-test environment. These stubs provide
 * just enough surface for the engine to run and for tests to observe cache
 * behaviour. They are never loaded on a real MediaWiki instance (the test
 * suite is pure PHP), so no conflict with the real classes can occur.
 *
 * @license GPL-2.0-or-later
 */

namespace Wikibase\Lib\Store;

use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;

/**
 * Minimal EntityRevisionLookup contract (the real interface lives in the
 * Wikibase extension).
 */
interface EntityRevisionLookup {
	/**
	 * @return EntityRevision|null
	 */
	public function getEntityRevision( EntityId $entityId, int $revisionId = 0, string $mode = 'from-db' );
}

/**
 * Minimal EntityRevision value object (the real class lives in the Wikibase
 * extension).
 */
class EntityRevision {
	/** @var Item */
	private $entity;

	/** @var int */
	private $revId;

	public function __construct( Item $entity, int $revId ) {
		$this->entity = $entity;
		$this->revId = $revId;
	}

	public function getEntity(): Item {
		return $this->entity;
	}

	public function getRevisionId(): int {
		return $this->revId;
	}
}
