<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\AuthorCreator;
use EmbeddableContent\Flow\AuthorResolver;
use EmbeddableContent\Flow\ClassicPageCreator;
use EmbeddableContent\Flow\ClassicPageSpec;
use EmbeddableContent\Flow\SourceFlowService;
use EmbeddableContent\Flow\StatementGuidAssigner;
use EmbeddableContent\Spec\AuthorNameList;
use EmbeddableContent\Spec\EntityLabelText;
use EmbeddableContent\Spec\ItemIdList;
use EmbeddableContent\Spec\CollisionFinder;
use EmbeddableContent\Spec\ItemLabelLookup;
use EmbeddableContent\Spec\LabelSanitizer;
use EmbeddableContent\Spec\LabelSuffix;
use EmbeddableContent\Spec\PageTitle;
use MediaWiki\Api\ApiBase;
use MediaWiki\Message\RawMessage;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\Repo\WikibaseRepo;

/**
 * api.php?action=addsource — the entity-mode AddSource flow for API clients
 * (bot sessions, the MCP embeddable-add-citation-source tool): creates or
 * updates a citable work item with the same validation, statement building
 * and classic Source: page + sitelink as Special:AddSource, driven by
 * SourceFieldMap so the accepted fields can never drift from the form.
 *
 * Create (no qid): class + title (+ the parent for child classes) → new
 * item, classic page when the class has one. Authors are optional.
 * Update (qid): replaces the statements for the fields provided, keeps
 * everything else (no-clobber), never changes the class.
 *
 * @license GPL-2.0-or-later
 */
class ApiAddSource extends ApiBase {

	/** @var SourceFlowService */
	private $flow;

	/** @var ClassicPageCreator */
	private $pageCreator;

	/** @var EmbeddableContentConfig */
	private $config;

	private AuthorResolver $authorResolver;

	private AuthorCreator $authorCreator;

	public function __construct(
		$mainModule,
		$moduleName,
		SourceFlowService $flow,
		ClassicPageCreator $pageCreator,
		EmbeddableContentConfig $config,
		AuthorResolver $authorResolver,
		AuthorCreator $authorCreator
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->flow = $flow;
		$this->pageCreator = $pageCreator;
		$this->config = $config;
		$this->authorResolver = $authorResolver;
		$this->authorCreator = $authorCreator;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$classKey = $params['class'];
		$qid = $params['qid'] !== null ? strtoupper( trim( $params['qid'] ) ) : null;
		if ( $qid !== null && preg_match( '/^Q[1-9]\d*$/', $qid ) !== 1 ) {
			$this->dieWithError( new RawMessage( "qid \"{$qid}\" is not an item ID." ), 'invalid_qid' );
		}
		$creating = $qid === null;

		$record = [];
		foreach ( $this->fieldParams() as $field ) {
			$value = $params[$field];
			if ( $value === null || $value === '' ) {
				continue;
			}
			if ( $field === 'translations' ) {
				// The law added translations ride as a JSON array of
				// {language, content} rows (the browser form's cloner field
				// submits the same shape) — the AddQuotation contract.
				$decoded = json_decode( $value, true );
				if ( !is_array( $decoded ) ) {
					$this->dieWithError(
						new RawMessage( 'translations must be a JSON array of {language, content} rows.' ),
						'invalid_input'
					);
				}
				$record[$field] = $decoded;
				continue;
			}
			$record[$field] = $value;
		}

		$user = $this->getUser();
		$summary = $params['summary'];
		$authorResult = null;
		$authorPayload = $this->applyAuthorPayload( $classKey, $params, $record, $user, $summary );
		if ( is_array( $authorPayload ) && isset( $authorPayload['error'] ) ) {
			$this->dieWithError( new RawMessage( (string)$authorPayload['error'] ), 'invalid_authors' );
		}
		if ( is_array( $authorPayload ) && isset( $authorPayload['resolution'] ) ) {
			$this->getResult()->addValue( null, 'source', [
				'authorConfirmationRequired' => '1',
				'authorResolution' => $authorPayload['resolution'],
				'authorWarnings' => $authorPayload['warnings'],
			] );
			return;
		}
		if ( is_array( $authorPayload ) && isset( $authorPayload['result'] ) ) {
			$authorResult = $authorPayload['result'];
		}

		$error = $this->flow->prepare( $classKey, $record, $creating );
		if ( $error !== null ) {
			$this->dieWithError( new RawMessage( $error ), 'invalid_input' );
		}

		$store = WikibaseRepo::getEntityStore();
		$confirmDuplicate = !empty( $params['confirmDuplicate'] );
		$collisionAction = $params['collisionAction'] ?? null;
		if ( !$creating && $collisionAction !== null ) {
			$this->dieWithError(
				new RawMessage( 'collisionAction is only valid when creating (no qid).' ),
				'invalid_input'
			);
		}

		if ( $creating ) {
			$label = $this->flow->labelFor( $classKey, $record );
			$language = SourceFlowService::termLanguage( $record );
			$pageSpec = $this->flow->pageSpecFor( $classKey );
			$targetTitle = $pageSpec !== null
				? PageTitle::fromLabel( $label, $pageSpec->namespace )
				: null;
			$labelWithSuffix = null;

			if ( $collisionAction !== null ) {
				$collision = CollisionFinder::find( $label, $language, $targetTitle );
				if ( $collision === null ) {
					$this->dieWithError(
						new RawMessage( 'No collision found for this label.' ),
						'no_collision'
					);
				}
				if ( $collisionAction === 'reuse' ) {
					$this->reuseCollisionItem(
						$collision, $classKey, $record, $label, $language, $pageSpec, $targetTitle, $summary, $user
					);
					return;
				}
				if ( $collisionAction === 'delete' ) {
					if ( !$user->isAllowed( 'delete' ) ) {
						$this->dieWithError(
							new RawMessage( 'You do not have permission to delete the existing item.' ),
							'permissiondenied'
						);
					}
					try {
						$store->deleteEntity(
							new ItemId( $collision['itemId'] ),
							$summary ?? 'Delete the colliding item during an Add* creation',
							$user
						);
					} catch ( \Throwable $e ) {
						$this->dieWithError( new RawMessage( $e->getMessage() ), 'delete_failed' );
					}
					$confirmDuplicate = true;
				}
				if ( $collisionAction === 'duplicate' ) {
					$labelWithSuffix = $this->labelWithSuffix(
						$classKey,
						$record,
						$this->collisionSuffix( $classKey, $record, $language, $pageSpec )
					);
					$confirmDuplicate = true;
				}
			} elseif ( $confirmDuplicate ) {
				// "Create anyway" from the duplicate guard: a same-label item
				// gets the next suffix instead of a hard duplicate.
				if ( ItemLabelLookup::findIdByLabel( $label, $language ) !== null ) {
					$labelWithSuffix = $this->labelWithSuffix(
						$classKey,
						$record,
						$this->collisionSuffix( $classKey, $record, $language, $pageSpec )
					);
				}
			}

			// Duplication guard: an existing item carrying the record's
			// external ids / URLs, or a highly similar label, aborts with a
			// duplicate result (no create) — machine clients decide;
			// confirmDuplicate=1 forces the create.
			if ( !$confirmDuplicate ) {
				$classId = $this->config->sourceClasses()[
					\EmbeddableContent\Flow\SourceFieldMap::formKey( $classKey )
				] ?? null;
				$duplicate = \EmbeddableContent\Spec\DuplicateChecker::find(
					$this->config,
					$record,
					$label,
					$classId !== null ? [ $classId ] : [],
					$language
				);
				if ( $duplicate !== null ) {
					$this->getResult()->addValue( null, 'source', [
						'duplicate' => '1',
						'duplicateOf' => $duplicate['itemId'],
						'duplicateLabel' => $duplicate['label'],
						'match' => $duplicate['match'],
					] );
					return;
				}
			}

			// Exact-label collision (the class-filtered guard above cannot
			// see an unclassified item). A target page owned by another item
			// stays with the existing duplicate/link-confirm flow.
			if ( !$confirmDuplicate ) {
				$collision = CollisionFinder::find( $label, $language, $targetTitle );
				if ( $collision !== null ) {
					$this->getResult()->addValue( null, 'source', [
						'collision' => '1',
						'collisionOf' => $collision['itemId'],
						'collisionLabel' => $collision['label'],
						'currentSitelink' => $collision['currentSitelink'],
						'targetTitle' => $collision['targetTitle'],
					] );
					return;
				}
			}

			$item = $this->flow->buildItem( $classKey, $record );
			if ( $labelWithSuffix !== null ) {
				$item->setLabel( $language, $labelWithSuffix );
			}
			$summaryText = $summary ?? 'Add source item';
			$store->saveEntity( $item, $summaryText, $user, EDIT_NEW );
			// The first save assigns the item id; statements must carry
			// GUIDs or the entity page renders them as empty edit-mode rows
			// for logged-in users (the client matches statements to the DOM
			// by GUID).
			StatementGuidAssigner::ensureGuids( $item, new GuidGenerator() );
			$revision = $store->saveEntity( $item, $summaryText, $user, EDIT_UPDATE );
			$itemId = $item->getId()->getSerialization();
			// A newly created CHILD item (bookExcerpt/webpage/youtubeVideo)
			// joins its parent's child-items listing — invalidate the
			// parent's classic page (best-effort, the form-flow pattern).
			if ( !empty( $record['parent'] ) ) {
				\EmbeddableContent\Spec\ChildItemLookup::invalidateParentPages( [ $record['parent'] ] );
			}

			$result = [
				'entityId' => $itemId,
				'entityType' => 'item',
				'latestRevisionId' => $revision->getRevisionId(),
				'created' => '1',
			];
			$record['itemId'] = $itemId;
			if ( $pageSpec !== null ) {
				$pageTitle = $this->pageCreator->createFor(
					$pageSpec,
					$labelWithSuffix ?? $label,
					$record,
					$user
				);
				if ( $pageTitle !== null ) {
					$result['pageTitle'] = $pageTitle;
				}
			}
		} else {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $qid ) );
			if ( !$entity instanceof Item ) {
				$this->dieWithError( new RawMessage( "Entity \"{$qid}\" not found." ), 'not_found' );
			}
			// The parent(s) BEFORE the update — a re-parented child must
			// refresh the OLD parent's listing row too.
			$previousParents = self::parentIdsOf( $entity, $this->config );
			$this->flow->applyUpdate( $classKey, $entity, $record );
			$revision = $store->saveEntity(
				$entity,
				$summary ?? 'Update source item',
				$user,
				EDIT_UPDATE
			);
			$result = [
				'entityId' => $entity->getId()->getSerialization(),
				'entityType' => 'item',
				'latestRevisionId' => $revision->getRevisionId(),
				'updated' => '1',
			];
			$invalidParents = $previousParents;
			if ( !empty( $record['parent'] ) ) {
				$invalidParents[] = $record['parent'];
			}
			if ( $invalidParents !== [] ) {
				\EmbeddableContent\Spec\ChildItemLookup::invalidateParentPages( $invalidParents );
			}
		}

		if ( $authorResult !== null ) {
			$result['authors'] = $authorResult;
		}
		$this->getResult()->addValue( null, 'source', $result );
	}

	/**
	 * The record's label with a duplicate suffix. The suffix goes BEFORE the
	 * class suffix ("The Hobbit (2) (Book)"), never after it.
	 *
	 * @param array<string,mixed> $record
	 */
	private function labelWithSuffix( string $classKey, array $record, string $suffix ): string {
		if ( $suffix === '' ) {
			return $this->flow->labelFor( $classKey, $record );
		}
		$title = LabelSanitizer::stripMarkup( trim( (string)( $record['title'] ?? '' ) ) );
		if ( $title === '' ) {
			// A derived label (law): append (there is no class suffix).
			return $this->flow->labelFor( $classKey, $record ) . $suffix;
		}
		$modified = $record;
		$modified['title'] = $title . $suffix;
		return $this->flow->labelFor( $classKey, $modified );
	}

	/**
	 * The next free duplicate suffix, checking the final label (with the
	 * class suffix) against both the item term store and the page title.
	 *
	 * @param array<string,mixed> $record
	 */
	private function collisionSuffix(
		string $classKey,
		array $record,
		string $language,
		?ClassicPageSpec $pageSpec
	): string {
		$base = $this->flow->labelFor( $classKey, $record );
		return LabelSuffix::nextSuffix(
			$base,
			function ( string $suffix ) use ( $classKey, $record, $language, $pageSpec ): bool {
				$candidate = $this->labelWithSuffix( $classKey, $record, $suffix );
				if ( ItemLabelLookup::findIdByLabel( $candidate, $language ) !== null ) {
					return true;
				}
				if ( $pageSpec !== null ) {
					$title = PageTitle::fromLabel( $candidate, $pageSpec->namespace );
					if ( $title !== null && $title->exists() ) {
						return true;
					}
				}
				return false;
			}
		);
	}

	/**
	 * collisionAction=reuse: apply the record to the existing source item,
	 * move its sitelink to the new page, and create the page if needed.
	 *
	 * @param array<string,mixed> $collision
	 * @param array<string,mixed> $record
	 */
	private function reuseCollisionItem(
		array $collision,
		string $classKey,
		array $record,
		string $label,
		string $language,
		?ClassicPageSpec $pageSpec,
		?\MediaWiki\Title\Title $targetTitle,
		?string $summary,
		$user
	): void {
		$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $collision['itemId'] ) );
		if ( !$entity instanceof Item ) {
			$this->dieWithError(
				new RawMessage( 'The colliding item could not be loaded.' ),
				'not_found'
			);
		}
		$this->flow->applyUpdate( $classKey, $entity, $record );
		$entity->setLabel( $language, $label );
		if ( $targetTitle !== null ) {
			$entity->getSiteLinkList()->setNewSiteLink( 'wikibase', $targetTitle->getPrefixedText() );
		}
		$revision = WikibaseRepo::getEntityStore()->saveEntity(
			$entity,
			$summary ?? 'Reuse the existing item during an Add* creation',
			$user,
			EDIT_UPDATE
		);
		if ( $targetTitle !== null ) {
			WikibaseRepo::getStore()->newSiteLinkStore()->saveLinksOfItem( $entity );
		}
		$result = [
			'entityId' => $entity->getId()->getSerialization(),
			'entityType' => 'item',
			'latestRevisionId' => $revision->getRevisionId(),
			'reused' => '1',
		];
		if ( $pageSpec !== null ) {
			$record['itemId'] = $result['entityId'];
			$pageTitle = $this->pageCreator->createFor( $pageSpec, $label, $record, $user );
			if ( $pageTitle !== null ) {
				$result['pageTitle'] = $pageTitle;
			}
		}
		$this->getResult()->addValue( null, 'source', $result );
	}

	/**
	 * Resolves/creates the authors supplied as a NAMES string.
	 *
	 * Two modes, both in one call:
	 *  - no authorDecisions → return the batch resolution (every name +
	 *    candidates) with authorConfirmationRequired=1 and write NOTHING;
	 *  - authorDecisions → create/reuse/update the decided Person items,
	 *    merge their ids into the record's authors, and continue creating
	 *    the Source.
	 *
	 * @param array<string,mixed> $params
	 * @param array<string,mixed> $record
	 * @return array{error?:string,resolution?:array,warnings?:array,result?:array}|null
	 */
	private function applyAuthorPayload(
		string $classKey,
		array $params,
		array &$record,
		$user,
		?string $summary
	): ?array {
		$namesInput = trim( (string)( $params['authorNames'] ?? '' ) );
		if ( $namesInput === '' ) {
			return null;
		}
		if ( !\EmbeddableContent\Flow\SourceFieldMap::acceptsField( $classKey, 'authors' ) ) {
			return [ 'error' => "classKey {$classKey} does not expose authors." ];
		}
		$parsed = AuthorNameList::parse(
			$namesInput,
			(string)( $params['authorNameFormat'] ?? AuthorNameList::FORMAT_AUTO )
		);
		if ( $parsed['names'] === [] ) {
			return [ 'error' => 'No person names could be parsed from authorNames.' ];
		}

		$decisions = $this->decodeJsonArray( (string)( $params['authorDecisions'] ?? '' ) );
		if ( $decisions === null ) {
			return [ 'error' => 'authorDecisions must be a JSON array.' ];
		}
		$details = $this->decodeJsonObject( (string)( $params['authorDetails'] ?? '' ) );
		if ( $details === null ) {
			return [ 'error' => 'authorDetails must be a JSON object.' ];
		}
		foreach ( $decisions as &$decision ) {
			if ( !is_array( $decision ) ) {
				continue;
			}
			$index = (int)( $decision['index'] ?? -1 );
			if ( $index < 0 || !isset( $parsed['names'][$index] ) ) {
				continue;
			}
			$provided = $this->detailsFor( $details, $index, $parsed['names'][$index]['original'] );
			if ( $provided === [] ) {
				continue;
			}
			$existing = is_array( $decision['person'] ?? null ) ? $decision['person'] : [];
			$decision['person'] = array_merge( $provided, $existing );
		}
		unset( $decision );

		if ( $decisions === [] ) {
			return [
				'resolution' => $this->authorResolver->resolveNames(
					$namesInput,
					$parsed['names'],
					(string)( $params['labelLanguage'] ?? 'en' )
				),
				'warnings' => $parsed['warnings'],
			];
		}

		$result = $this->authorCreator->applyDecisions(
			$parsed['names'],
			$decisions,
			$user,
			$summary ?? 'Create an author from action=addsource',
			!empty( $params['authorUpdateExisting'] )
		);
		if ( $result['error'] !== null ) {
			return [ 'error' => $result['error'] ];
		}

		$ids = ItemIdList::split( (string)( $record['authors'] ?? '' ) );
		foreach ( $result['ids'] as $id ) {
			if ( !in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		$record['authors'] = implode( ', ', $ids );

		if ( empty( $params['allowCollectiveAuthor'] ) ) {
			$guardError = $this->collectiveGuard( $record['authors'] );
			if ( $guardError !== null ) {
				return [ 'error' => $guardError ];
			}
		}
		return [ 'result' => $result ];
	}

	/**
	 * @param array<string,mixed> $details
	 * @return array<string,mixed>
	 */
	private function detailsFor( array $details, int $index, string $original ): array {
		foreach ( [ (string)$index, $original ] as $key ) {
			if ( isset( $details[$key] ) && is_array( $details[$key] ) ) {
				return $details[$key];
			}
		}
		return [];
	}

	/** @return array<int,mixed>|null */
	private function decodeJsonArray( string $json ): ?array {
		if ( trim( $json ) === '' ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) && array_is_list( $decoded ) ? $decoded : null;
	}

	/** @return array<string,mixed>|null */
	private function decodeJsonObject( string $json ): ?array {
		if ( trim( $json ) === '' ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) && !array_is_list( $decoded ) ? $decoded : null;
	}

	/**
	 * Rejects a group-of-humans author whose label is a person-name list
	 * (the legacy shortcut) unless the caller explicitly allowed it.
	 */
	private function collectiveGuard( string $authorIds ): ?string {
		$groupClass = $this->config->agentClasses()['groupOfHumans'] ?? null;
		if ( $groupClass === null ) {
			return null;
		}
		foreach ( ItemIdList::split( $authorIds ) as $id ) {
			if ( preg_match( '/^Q[1-9]\d*$/', $id ) !== 1 ) {
				continue;
			}
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $id ) );
			if ( !$item instanceof Item ) {
				continue;
			}
			if ( !\EmbeddableContent\EntityClassFilter::hasAnyClass(
				$item,
				[ $groupClass ],
				$this->config->instanceOfPropertyId()
			) ) {
				continue;
			}
			$label = EntityLabelText::of( $item ) ?? $id;
			$parsed = AuthorNameList::parse( $label );
			if ( count( $parsed['names'] ) >= 2 ) {
				return "author \"{$label}\" ({$id}) looks like a person-name list stored as a "
					. 'Group of humans; pass the individuals via authorNames, or allowCollectiveAuthor=1.';
			}
		}
		return null;
	}

	public function getAllowedParams() {
		$params = [
			'class' => [
				self::PARAM_TYPE => \EmbeddableContent\Flow\SourceFieldMap::CLASS_KEYS,
				self::PARAM_REQUIRED => true,
			],
			'qid' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
			],
			'summary' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
			],
			// Force the create past the duplication guard (the default: a
			// duplicate hit returns { duplicate: 1, duplicateOf, match }).
			'confirmDuplicate' => [
				self::PARAM_TYPE => 'boolean',
				self::PARAM_REQUIRED => false,
			],
			// Resolve an exact-label collision: reuse the existing item and
			// move its sitelink, delete it, or create a suffixed duplicate.
			'collisionAction' => [
				self::PARAM_TYPE => [ 'reuse', 'delete', 'duplicate' ],
				self::PARAM_REQUIRED => false,
			],
			// Batch author resolution: paste the names, get every local
			// candidate in one call, then submit decisions in one write call.
			'authorNames' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_MAX_BYTES => 2000,
			],
			'authorNameFormat' => [
				self::PARAM_TYPE => [
					AuthorNameList::FORMAT_AUTO,
					AuthorNameList::FORMAT_COMMA,
					AuthorNameList::FORMAT_SEMICOLON,
					AuthorNameList::FORMAT_SINGLE,
				],
				self::PARAM_DFLT => AuthorNameList::FORMAT_AUTO,
			],
			// JSON array of {index, action: reuse|create|update|skip, qid?, person?}
			'authorDecisions' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_MAX_BYTES => 50000,
			],
			// JSON object keyed by name index or original name; person fields
			// for a new/updated Person (givenName, familyName, description,
			// ORCID, VIAF, ISNI, website, dates, …).
			'authorDetails' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_MAX_BYTES => 50000,
			],
			'authorUpdateExisting' => [
				self::PARAM_TYPE => 'boolean',
				self::PARAM_REQUIRED => false,
			],
			'allowCollectiveAuthor' => [
				self::PARAM_TYPE => 'boolean',
				self::PARAM_REQUIRED => false,
			],
		];
		foreach ( $this->fieldParams() as $field ) {
			$params[$field] = [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
			];
		}
		return $params;
	}

	public function isWriteMode() {
		return true;
	}

	public function mustBePosted() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	/** @return string[] the entity-mode record fields */
	private function fieldParams(): array {
		// The single source of truth (the field-map contract) — a hardcoded
		// copy here is exactly how the "webpage rejects authors yet demands
		// one" drift happened.
		return \EmbeddableContent\Flow\SourceFieldMap::ALL_FIELDS;
	}

	/**
	 * The `parent` statement values (item ids) of an item — the part-of
	 * parents, for invalidating the old parent's child-items row when a
	 * child is re-parented via the API.
	 *
	 * @return string[]
	 */
	private static function parentIdsOf( Item $item, EmbeddableContentConfig $config ): array {
		$propertyId = $config->sourcePropertyIds()['partOf'] ?? null;
		if ( $propertyId === null ) {
			return [];
		}
		$ids = [];
		foreach ( $item->getStatements()->getByPropertyId( new \Wikibase\DataModel\Entity\NumericPropertyId( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof \Wikibase\DataModel\Snak\PropertyValueSnak ) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof \Wikibase\DataModel\Entity\EntityIdValue ) {
				$id = $value->getEntityId()->getSerialization();
				if ( preg_match( '/^Q[1-9]\d*$/i', $id ) === 1 ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}
}
