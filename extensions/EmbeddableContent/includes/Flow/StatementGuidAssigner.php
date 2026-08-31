<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Services\Statement\GuidGenerator;

/**
 * Assigns statement GUIDs to an item's statements that lack them.
 *
 * Wikibase's entity-page client matches the server-rendered statement DOM to
 * the entity's JSON statements BY GUID (ViewFactory.getStatementGroupListView
 * → getStatementForGuid); a GUID-less statement never matches, so an
 * edit-capable (logged-in) view renders it as an EMPTY statement view that
 * auto-starts edit mode — the "item page in edit mode with content gone"
 * bug of 2026-08-31 (every item created through the flow services since the
 * browser-forms-delegate-to-services refactor). Wikibase's own write path
 * assigns GUIDs in ChangeOpStatement::apply; flows that bypass ChangeOps
 * (direct EntityStore::saveEntity) must assign them themselves.
 *
 * Call AFTER the entity has an id: for a new item that means after the FIRST
 * save (the EntityStore sets the id on the object — the ImageItemCreator /
 * createOrSkipItem pattern), for an existing item at any point. Idempotent:
 * statements that already carry a GUID are never rewritten (the no-clobber
 * contract — see createOrSkipItem's sitelink guard).
 *
 * @license GPL-2.0-or-later
 */
final class StatementGuidAssigner {

	/**
	 * @throws \LogicException when the item has no entity id yet (a new item
	 *         must be saved once before its GUIDs can be generated)
	 */
	public static function ensureGuids( Item $item, GuidGenerator $generator ): void {
		$entityId = $item->getId();
		if ( $entityId === null ) {
			throw new \LogicException(
				'Statement GUIDs require a known entity id: save a new item once before StatementGuidAssigner::ensureGuids().'
			);
		}
		foreach ( $item->getStatements() as $statement ) {
			if ( $statement->getGuid() === null ) {
				$statement->setGuid( $generator->newGuid( $entityId ) );
			}
		}
	}

}
