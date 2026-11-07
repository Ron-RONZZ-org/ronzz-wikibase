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
	public function validateDecisions( array $names, array $decisions ): array {
		$byIndex = [];
		foreach ( $decisions as $decision ) {
			$index = (int)( $decision['index'] ?? -1 );
			if ( $index >= 0 && $index < count( $names ) ) {
				$byIndex[$index] = $decision;
			}
		}
		$ids = [];
		foreach ( $names as $index => $name ) {
			$decision = $byIndex[$index] ?? null;
			if ( $decision === null ) {
				return [
					'ids' => [],
					'error' => 'Every person name needs a decision: confirm an existing Person, '
						. 'create a new one, or skip it. Missing: ' . $name['original'],
				];
			}
			$action = (string)( $decision['action'] ?? '' );
			$qid = strtoupper( trim( (string)( $decision['qid'] ?? '' ) ) );
			if ( $action === 'skip' ) {
				continue;
			}
			if ( $action === 'reuse' || $action === 'update' ) {
				if ( $this->loadPerson( $qid ) === null ) {
					return [ 'ids' => [], 'error' => "Person \"{$qid}\" is not an existing Person item." ];
				}
				$ids[] = $qid;
				continue;
			}
			if ( $action === 'create' ) {
				$details = is_array( $decision['person'] ?? null ) ? $decision['person'] : [];
				$record = $this->personRecord( $name, $details );
				$error = $this->flow->prepare( 'person', $record, true );
				if ( $error !== null ) {
					return [ 'ids' => [], 'error' => $error ];
				}
				continue;
			}
			return [ 'ids' => [], 'error' => "Unknown decision \"{$action}\" for person {$name['original']}." ];
		}
		return [ 'ids' => $ids, 'error' => null ];
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
		bool $updateExisting = false
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
			'warnings' => [],
			'error' => null,
		];
		foreach ( $names as $index => $name ) {
			$decision = $byIndex[$index] ?? null;
			if ( $decision === null ) {
				$result['error'] = 'Every person name needs a decision: confirm an existing Person, '
					. 'create a new one, or skip it. Missing: ' . $name['original'];
				return $result;
			}
			$action = (string)( $decision['action'] ?? '' );
			$qid = strtoupper( trim( (string)( $decision['qid'] ?? '' ) ) );
			$details = is_array( $decision['person'] ?? null ) ? $decision['person'] : [];

			switch ( $action ) {
				case 'skip':
					break;
				case 'reuse':
					$person = $this->loadPerson( $qid );
					if ( $person === null ) {
						$result['error'] = "Person \"{$qid}\" is not an existing Person item.";
						return $result;
					}
					$result['ids'][] = $qid;
					$result['reused'][] = $qid;
					if ( $updateExisting && $details !== [] ) {
						$error = $this->updatePerson( $person, $details, $user, $summary );
						if ( $error !== null ) {
							$result['error'] = $error;
							return $result;
						}
						$result['updated'][] = $qid;
					}
					break;
				case 'create':
					$created = $this->createOrReusePerson( $name, $details, $user, $summary, $result );
					if ( $created === null ) {
						return $result;
					}
					$result['ids'][] = $created;
					break;
				case 'update':
					$person = $this->loadPerson( $qid );
					if ( $person === null ) {
						$result['error'] = "Person \"{$qid}\" is not an existing Person item.";
						return $result;
					}
					$error = $this->updatePerson( $person, $details, $user, $summary );
					if ( $error !== null ) {
						$result['error'] = $error;
						return $result;
					}
					$result['ids'][] = $qid;
					$result['updated'][] = $qid;
					break;
				default:
					$result['error'] = "Unknown decision \"{$action}\" for person {$name['original']}.";
					return $result;
			}
		}
		return $result;
	}

	/**
	 * Creates a Person, or reuses the exact-label Person when one exists.
	 *
	 * @param array{original:string,givenName:string,familyName:string,familyFirst:bool,flags:list<string>} $name
	 * @param array<string,mixed> $details
	 * @param array{ids:list<string>,created:list<string>,reused:list<string>,updated:list<string>,warnings:list<string>,error:string|null} $result
	 */
	private function createOrReusePerson( array $name, array $details, User $user, string $summary, array &$result ): ?string {
		$record = $this->personRecord( $name, $details );
		$label = $this->flow->labelFor( 'person', $record );
		$language = SemanticEntityFlowService::termLanguage( $record );
		$exact = ItemLabelLookup::findIdByLabel( $label, $language );
		if ( $exact !== null ) {
			$person = $this->loadPerson( $exact );
			if ( $person === null ) {
				$result['error'] = "The exact-label item {$exact} for \"{$label}\" is not a Person.";
				return null;
			}
			$result['reused'][] = $exact;
			$result['warnings'][] = "\"{$label}\" already exists as {$exact}; reused it instead of creating a duplicate.";
			return $exact;
		}

		$error = $this->flow->prepare( 'person', $record, true );
		if ( $error !== null ) {
			$result['error'] = $error;
			return null;
		}
		$item = $this->flow->buildItem( 'person', $record );
		$store = WikibaseRepo::getEntityStore();
		$store->saveEntity( $item, $summary, $user, EDIT_NEW );
		StatementGuidAssigner::ensureGuids( $item, new GuidGenerator() );
		$store->saveEntity( $item, $summary, $user, EDIT_UPDATE );
		$itemId = $item->getId()->getSerialization();

		$this->createPersonPage( $itemId, $label, $record, $user );
		$result['created'][] = $itemId;
		return $itemId;
	}

	/**
	 * @param array<string,mixed> $details
	 */
	private function updatePerson( Item $item, array $details, User $user, string $summary ): ?string {
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

}
