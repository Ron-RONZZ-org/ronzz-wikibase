<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\EntityClassFilter;
use EmbeddableContent\Spec\EntityLabelText;
use EmbeddableContent\Spec\ItemLabelLookup;
use EmbeddableContent\Spec\NameSplitter;
use MediaWiki\User\User;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\Repo\WikibaseRepo;

/**
 * Creates/reuses the Person items decided by the AddSource author panel.
 *
 * The browser and API pass explicit per-name decisions (never an inference):
 *  - reuse  — an existing Person Q-id;
 *  - create — a new Person from the parsed name + optional details;
 *  - update — replace the provided details on an existing Person;
 *  - skip   — do not add the author.
 *
 * Every name must have a decision; a missing one aborts with a form error so
 * a common name is never silently bound to the wrong person. A `create`
 * whose exact label already exists on a Person reuses that Person instead of
 * making a duplicate.
 *
 * Created Persons get the same classic `Person:` page + sitelink as
 * Special:AddPerson (ClassicPageCreator), so the name flow is not a
 * second-class author entity.
 *
 * @license GPL-2.0-or-later
 */
final class AuthorCreator {

	public function __construct(
		private readonly EmbeddableContentConfig $config,
		private readonly SemanticEntityFlowService $flow,
		private readonly ClassicPageCreator $pageCreator
	) {
	}

	/**
	 * Read-only pre-flight: every parsed name has a decision, every reuse/
	 * update qid is an existing Person, and every create record passes the
	 * flow validation. No entity is written — used by the AddSource review
	 * step so a review submit does not create Persons before the item is
	 * actually created.
	 *
	 * @param array<int,array{original:string,givenName:string,familyName:string,familyFirst:bool,flags:list<string>}> $names
	 * @param array<int,array{index?:int,action?:string,qid?:string,person?:array<string,mixed>}> $decisions
	 * @return array{ids:list<string>,error:string|null}
	 */
	public function validateDecisions(
		array $names,
		array $decisions,
		array $allowedKinds = [ 'person', 'collective' ]
	): array {
		$byIndex = [];
		foreach ( $decisions as $decision ) {
			$index = (int)( $decision['index'] ?? -1 );
			if ( $index >= 0 && $index < count( $names ) ) {
				$byIndex[$index] = $decision;
			}
		}
		$ids = [];
		$collectiveIds = [];
		foreach ( $names as $index => $name ) {
			$decision = $byIndex[$index] ?? null;
			if ( $decision === null ) {
				return [
					'ids' => [],
					'error' => 'Every author needs a decision: confirm an existing entity, '
						. 'create a new one, or remove it. Missing: ' . $name['original'],
				];
			}
			$kind = $this->kindOf( $name );
			if ( !in_array( $kind, $allowedKinds, true ) ) {
				return [
					'ids' => [],
					'error' => $kind === 'collective'
						? 'A collective cannot be a group member: ' . $name['original']
						: 'A person cannot be a collective author: ' . $name['original'],
				];
			}
			$action = (string)( $decision['action'] ?? '' );
			$qid = strtoupper( trim( (string)( $decision['qid'] ?? '' ) ) );
			if ( $action === 'skip' ) {
				continue;
			}
			if ( $action === 'reuse' || $action === 'update' ) {
				if ( $this->loadAgent( $qid, $kind ) === null ) {
					return [ 'ids' => [], 'collectiveIds' => [], 'error' => "{$qid} is not an existing {$kind} item." ];
				}
				$ids[] = $qid;
				if ( $kind === 'collective' ) {
					$collectiveIds[] = $qid;
				}
				continue;
			}
			if ( $action === 'create' ) {
				$details = is_array( $decision[$kind] ?? null ) ? $decision[$kind] : [];
				$record = $kind === 'collective'
					? $this->collectiveRecord( $name, $details )
					: $this->personRecord( $name, $details );
				$error = $this->flow->prepare( $kind, $record, true );
				if ( $error !== null ) {
					return [ 'ids' => [], 'error' => $error ];
				}
				continue;
			}
			return [ 'ids' => [], 'error' => "Unknown decision \"{$action}\" for {$name['original']}." ];
		}
		return [ 'ids' => $ids, 'collectiveIds' => $collectiveIds, 'error' => null ];
	}

	/**
	 * @param array<int,array{original:string,givenName:string,familyName:string,familyFirst:bool,flags:list<string>}> $names
	 * @param array<int,array{index?:int,action?:string,qid?:string,person?:array<string,mixed>}> $decisions
	 * @return array{
	 *   ids: list<string>,
	 *   created: list<string>,
	 *   reused: list<string>,
	 *   updated: list<string>,
	 *   warnings: list<string>,
	 *   error: string|null
	 * }
	 */
	public function applyDecisions(
		array $names,
		array $decisions,
		User $user,
		string $summary,
		bool $updateExisting = false,
		array $allowedKinds = [ 'person', 'collective' ]
	): array {
		$byIndex = [];
		foreach ( $decisions as $decision ) {
			$index = (int)( $decision['index'] ?? -1 );
			if ( $index >= 0 && $index < count( $names ) ) {
				$byIndex[$index] = $decision;
			}
		}

		$result = [
			'ids' => [],
			'created' => [],
			'reused' => [],
			'updated' => [],
			'collectiveIds' => [],
			'warnings' => [],
			'error' => null,
		];
		foreach ( $names as $index => $name ) {
			$decision = $byIndex[$index] ?? null;
			if ( $decision === null ) {
				$result['error'] = 'Every author needs a decision: confirm an existing entity, '
					. 'create a new one, or remove it. Missing: ' . $name['original'];
				return $result;
			}
			$kind = $this->kindOf( $name );
			if ( !in_array( $kind, $allowedKinds, true ) ) {
				$result['error'] = $kind === 'collective'
					? 'A collective cannot be a group member: ' . $name['original']
					: 'A person cannot be a collective author: ' . $name['original'];
				return $result;
			}
			$action = (string)( $decision['action'] ?? '' );
			$qid = strtoupper( trim( (string)( $decision['qid'] ?? '' ) ) );
			$details = is_array( $decision[$kind] ?? null ) ? $decision[$kind] : [];

			switch ( $action ) {
				case 'skip':
					break;
				case 'reuse':
					$entity = $this->loadAgent( $qid, $kind );
					if ( $entity === null ) {
						$result['error'] = "{$qid} is not an existing {$kind} item.";
						return $result;
					}
					$result['ids'][] = $qid;
					$result['reused'][] = $qid;
					if ( $kind === 'collective' ) {
						$result['collectiveIds'][] = $qid;
					}
					if ( $updateExisting && $details !== [] ) {
						$error = $this->updateAgent( $entity, $kind, $details, $user, $summary );
						if ( $error !== null ) {
							$result['error'] = $error;
							return $result;
						}
						$result['updated'][] = $qid;
					}
					break;
				case 'create':
					$created = $this->createOrReuseAgent( $name, $kind, $details, $user, $summary, $result );
					if ( $created === null ) {
						return $result;
					}
					$result['ids'][] = $created;
					if ( $kind === 'collective' ) {
						$result['collectiveIds'][] = $created;
					}
					break;
				case 'update':
					$entity = $this->loadAgent( $qid, $kind );
					if ( $entity === null ) {
						$result['error'] = "{$qid} is not an existing {$kind} item.";
						return $result;
					}
					$error = $this->updateAgent( $entity, $kind, $details, $user, $summary );
					if ( $error !== null ) {
						$result['error'] = $error;
						return $result;
					}
					$result['ids'][] = $qid;
					$result['updated'][] = $qid;
					if ( $kind === 'collective' ) {
						$result['collectiveIds'][] = $qid;
					}
					break;
				default:
					$result['error'] = "Unknown decision \"{$action}\" for {$name['original']}.";
					return $result;
			}
		}
		return $result;
	}

	/** @param array<string,mixed> $name */
	private function kindOf( array $name ): string {
		return ( $name['kind'] ?? 'person' ) === 'collective' ? 'collective' : 'person';
	}

	/**
	 * Creates the decided entity, or reuses the exact-label entity when one
	 * exists. A kind=collective row creates an organisation/collective item
	 * (with its Collective: page) — the explicit ORG: declaration.
	 *
	 * @param array{original:string,givenName:string,familyName:string,familyFirst:bool,flags:list<string>,kind?:string} $name
	 * @param array<string,mixed> $details
	 * @param array{ids:list<string>,created:list<string>,reused:list<string>,updated:list<string>,warnings:list<string>,error:string|null} $result
	 */
	private function createOrReuseAgent(
		array $name,
		string $kind,
		array $details,
		User $user,
		string $summary,
		array &$result
	): ?string {
		$record = $kind === 'collective'
			? $this->collectiveRecord( $name, $details )
			: $this->personRecord( $name, $details );
		$label = $this->flow->labelFor( $kind, $record );
		$language = SemanticEntityFlowService::termLanguage( $record );
		$exact = ItemLabelLookup::findIdByLabel( $label, $language );
		if ( $exact !== null ) {
			$existing = $this->loadAgent( $exact, $kind );
			if ( $existing === null ) {
				$result['error'] = "The exact-label item {$exact} for \"{$label}\" is not a {$kind}.";
				return null;
			}
			$result['reused'][] = $exact;
			$result['warnings'][] = "\"{$label}\" already exists as {$exact}; reused it instead of creating a duplicate.";
			return $exact;
		}

		$error = $this->flow->prepare( $kind, $record, true );
		if ( $error !== null ) {
			$result['error'] = $error;
			return null;
		}
		$item = $this->flow->buildItem( $kind, $record );
		$store = WikibaseRepo::getEntityStore();
		$store->saveEntity( $item, $summary, $user, EDIT_NEW );
		StatementGuidAssigner::ensureGuids( $item, new GuidGenerator() );
		$store->saveEntity( $item, $summary, $user, EDIT_UPDATE );
		$itemId = $item->getId()->getSerialization();

		if ( $kind === 'collective' ) {
			$this->createCollectivePage( $itemId, $label, $record, $user );
		} else {
			$this->createPersonPage( $itemId, $label, $record, $user );
		}
		$result['created'][] = $itemId;
		return $itemId;
	}

	/**
	 * @param array<string,mixed> $details
	 */
	private function updateAgent( Item $item, string $kind, array $details, User $user, string $summary ): ?string {
		if ( $kind === 'collective' ) {
			return $this->updateCollective( $item, $details, $user, $summary );
		}
		$record = $this->personRecord(
			[
				'original' => '',
				'givenName' => '',
				'familyName' => '',
				'familyFirst' => false,
				'flags' => [],
			],
			$details,
			false
		);
		$error = $this->flow->prepare( 'person', $record, false );
		if ( $error !== null ) {
			return $error;
		}
		// A partial name edit (only given or only family) keeps the other
		// part from the current label so the new label does not silently
		// drop it.
		$split = NameSplitter::splitFullName( EntityLabelText::of( $item ) ?? '' );
		if ( empty( $record['givenName'] ) && !empty( $record['familyName'] ) ) {
			$record['givenName'] = $split['givenName'];
		}
		if ( empty( $record['familyName'] ) && !empty( $record['givenName'] ) ) {
			$record['familyName'] = $split['familyName'];
		}
		$this->flow->applyUpdate( 'person', $item, $record );
		$language = SemanticEntityFlowService::termLanguage( $record );
		$label = trim( (string)( $record['givenName'] ?? '' ) . ' ' . (string)( $record['familyName'] ?? '' ) );
		if ( $label !== '' ) {
			$item->setLabel( $language, $label );
		}
		$description = trim( (string)( $record['description'] ?? '' ) );
		if ( $description !== '' ) {
			$item->setDescription( $language, $description );
		}
		WikibaseRepo::getEntityStore()->saveEntity( $item, $summary, $user, EDIT_UPDATE );
		return null;
	}

	/**
	 * @param array<string,mixed> $details
	 */
	private function updateCollective( Item $item, array $details, User $user, string $summary ): ?string {
		$record = $this->collectiveRecord(
			[ 'original' => '', 'givenName' => '', 'familyName' => '', 'familyFirst' => false, 'flags' => [] ],
			$details,
			false
		);
		$error = $this->flow->prepare( 'collective', $record, false );
		if ( $error !== null ) {
			return $error;
		}
		$this->flow->applyUpdate( 'collective', $item, $record );
		$language = SemanticEntityFlowService::termLanguage( $record );
		$label = trim( (string)( $record['label'] ?? '' ) );
		if ( $label !== '' ) {
			$item->setLabel( $language, $label );
		}
		$description = trim( (string)( $record['description'] ?? '' ) );
		if ( $description !== '' ) {
			$item->setDescription( $language, $description );
		}
		WikibaseRepo::getEntityStore()->saveEntity( $item, $summary, $user, EDIT_UPDATE );
		return null;
	}

	/**
	 * The flow record for a person: parsed name + the popup details.
	 *
	 * @param array{original:string,givenName:string,familyName:string,familyFirst:bool,flags:list<string>} $name
	 * @param array<string,mixed> $details
	 * @return array<string,mixed>
	 */
	private function personRecord( array $name, array $details, bool $useParsedName = true ): array {
		$record = [];
		$allowed = [
			'givenName', 'familyName', 'description', 'labelLanguage',
			'dateOfBirth', 'dateOfDeath', 'orcid', 'viafId', 'isni',
			'wikidataId', 'openalexAuthorId', 'officialWebsite',
			'affiliatedWith',
		];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $details ) && $details[$field] !== null && $details[$field] !== '' ) {
				$record[$field] = $details[$field];
			}
		}
		if ( $useParsedName ) {
			if ( empty( $record['givenName'] ) && $name['givenName'] !== '' ) {
				$record['givenName'] = $name['givenName'];
			}
			if ( empty( $record['familyName'] ) && $name['familyName'] !== '' ) {
				$record['familyName'] = $name['familyName'];
			}
		}
		if ( empty( $record['labelLanguage'] ) ) {
			$record['labelLanguage'] = 'en';
		}
		return $record;
	}

	/**
	 * The flow record for a collective: the ORG name + the popup details.
	 *
	 * @param array<string,mixed> $name
	 * @param array<string,mixed> $details
	 * @return array<string,mixed>
	 */
	private function collectiveRecord( array $name, array $details, bool $useParsedName = true ): array {
		$record = [];
		$allowed = [
			'label', 'description', 'labelLanguage', 'collectiveClass',
			'parentOrganization', 'officialWebsite', 'wikidataId',
		];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $details ) && $details[$field] !== null && $details[$field] !== '' ) {
				$record[$field] = $details[$field];
			}
		}
		if ( $useParsedName && empty( $record['label'] ) && trim( (string)( $name['familyName'] ?? '' ) ) !== '' ) {
			$record['label'] = trim( (string)$name['familyName'] );
		}
		if ( empty( $record['labelLanguage'] ) ) {
			$record['labelLanguage'] = 'en';
		}
		if ( empty( $record['collectiveClass'] ) ) {
			$record['collectiveClass'] = $this->config->agentClasses()['organization'] ?? 'organization';
		}
		return $record;
	}

	/** A Person item by id, or null (an existing non-Person id is rejected). */
	private function loadPerson( string $qid ): ?Item {
		if ( preg_match( '/^Q[1-9]\d*$/', $qid ) !== 1 ) {
			return null;
		}
		$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $qid ) );
		if ( !$entity instanceof Item ) {
			return null;
		}
		$personClass = $this->config->agentClasses()['person'] ?? null;
		if ( $personClass === null ) {
			return $entity;
		}
		return EntityClassFilter::hasAnyClass(
			$entity,
			[ $personClass ],
			$this->config->instanceOfPropertyId()
		) ? $entity : null;
	}

	/**
	 * Loads a Person or a collective (any non-person agent class) by id.
	 */
	private function loadAgent( string $qid, string $kind ): ?Item {
		if ( $kind === 'person' ) {
			return $this->loadPerson( $qid );
		}
		if ( preg_match( '/^Q[1-9]\d*$/', $qid ) !== 1 ) {
			return null;
		}
		$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $qid ) );
		if ( !$entity instanceof Item ) {
			return null;
		}
		$personClass = $this->config->agentClasses()['person'] ?? null;
		if ( $personClass !== null && EntityClassFilter::hasAnyClass(
			$entity,
			[ $personClass ],
			$this->config->instanceOfPropertyId()
		) ) {
			return null;
		}
		$collectiveClasses = array_values( array_diff(
			$this->config->agentClasses(),
			$personClass !== null ? [ $personClass ] : []
		) );
		if ( $collectiveClasses === [] ) {
			return $entity;
		}
		return EntityClassFilter::hasAnyClass(
			$entity,
			$collectiveClasses,
			$this->config->instanceOfPropertyId()
		) ? $entity : null;
	}

	/**
	 * Creates the classic Person: page + sitelink (the AddPerson machinery).
	 *
	 * @param array<string,mixed> $record
	 */
	private function createPersonPage( string $itemId, string $label, array $record, User $user ): void {
		if ( !defined( 'NS_PERSON' ) ) {
			return;
		}
		$record['itemId'] = $itemId;
		$this->pageCreator->createFor(
			new ClassicPageSpec( NS_PERSON, 'Person' ),
			$label,
			$record,
			$user
		);
	}

	/**
	 * Creates the classic Collective: page + sitelink (the AddCollective
	 * machinery) for an ORG: author created from the panel.
	 *
	 * @param array<string,mixed> $record
	 */
	private function createCollectivePage( string $itemId, string $label, array $record, User $user ): void {
		if ( !defined( 'NS_COLLECTIVE' ) ) {
			return;
		}
		$record['itemId'] = $itemId;
		$this->pageCreator->createFor(
			new ClassicPageSpec( NS_COLLECTIVE, 'Collective' ),
			$label,
			$record,
			$user
		);
	}

}
