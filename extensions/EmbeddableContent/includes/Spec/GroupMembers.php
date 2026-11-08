<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\EntityClassFilter;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/**
 * Reads a group-of-humans item's `has member` statements and expands a group
 * author to its individual members.
 *
 * Read-only. Used by the AddSource author validation and the
 * action=addsource API so a source stores its actual authors, not the
 * convenience grouping item. A group with no members is blocked only when
 * its label is a person-name list (the legacy shortcut); a genuine
 * non-name-list collective can still be an author.
 *
 * @license GPL-2.0-or-later
 */
final class GroupMembers {

	public function __construct(
		private readonly EmbeddableContentConfig $config,
		private readonly EntityLookup $lookup
	) {
	}

	public function isGroupOfHumans( Item $item ): bool {
		$groupClass = $this->config->agentClasses()['groupOfHumans'] ?? null;
		return $groupClass !== null && EntityClassFilter::hasAnyClass(
			$item,
			[ $groupClass ],
			$this->config->instanceOfPropertyId()
		);
	}

	/**
	 * @return array{group: array<string,mixed>, members: list<array{qid:string,label:string}>}
	 */
	public function membersOf( string $groupId ): array {
		$item = $this->loadItem( $groupId );
		$group = [
			'qid' => $groupId,
			'label' => $item instanceof Item ? ( EntityLabelText::of( $item ) ?? $groupId ) : $groupId,
		];
		$members = [];
		$propertyId = $this->config->collectivePropertyIds()['hasMember'] ?? null;
		if ( $item instanceof Item && $propertyId !== null ) {
			foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
				$snak = $statement->getMainSnak();
				if ( !$snak instanceof PropertyValueSnak ) {
					continue;
				}
				$value = $snak->getDataValue();
				if ( !$value instanceof \Wikibase\DataModel\Entity\EntityIdValue ) {
					continue;
				}
				$memberId = $value->getEntityId()->getSerialization();
				$member = $this->loadItem( $memberId );
				$members[] = [
					'qid' => $memberId,
					'label' => $member instanceof Item
						? ( EntityLabelText::of( $member ) ?? $memberId )
						: $memberId,
				];
			}
		}
		return [ 'group' => $group, 'members' => $members ];
	}

	/**
	 * Replaces a group-of-humans id with its `has member` Persons. A group
	 * without members is blocked when its label parses as a person-name list
	 * unless its Q-id is in `$allowNameListCollectiveIds` (an explicit ORG:
	 * declaration); a non-name-list group is kept as a collective author.
	 *
	 * @param string[] $ids
	 * @param string[] $allowNameListCollectiveIds
	 * @return array{ids:list<string>,blocked:list<array{qid:string,label:string}>}
	 */
	public function expand( array $ids, array $allowNameListCollectiveIds = [] ): array {
		$out = [];
		$blocked = [];
		foreach ( $ids as $id ) {
			$id = strtoupper( trim( (string)$id ) );
			if ( preg_match( '/^Q[1-9]\d*$/', $id ) !== 1 ) {
				continue;
			}
			$item = $this->loadItem( $id );
			if ( !$item instanceof Item || !$this->isGroupOfHumans( $item ) ) {
				if ( !in_array( $id, $out, true ) ) {
					$out[] = $id;
				}
				continue;
			}
			$members = $this->membersOf( $id )['members'];
			if ( $members !== [] ) {
				foreach ( $members as $member ) {
					if ( !in_array( $member['qid'], $out, true ) ) {
						$out[] = $member['qid'];
					}
				}
				continue;
			}
			$label = EntityLabelText::of( $item ) ?? $id;
			$parsed = AuthorNameList::parse( $label );
			if ( count( $parsed['names'] ) >= 2
				&& !in_array( $id, $allowNameListCollectiveIds, true )
			) {
				$blocked[] = [ 'qid' => $id, 'label' => $label ];
				continue;
			}
			if ( !in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return [ 'ids' => $out, 'blocked' => $blocked ];
	}

	private function loadItem( string $itemId ): ?Item {
		if ( preg_match( '/^Q[1-9]\d*$/', $itemId ) !== 1 ) {
			return null;
		}
		try {
			$entity = $this->lookup->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $entity instanceof Item ? $entity : null;
	}

}
