<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\ClassicPageCreator;
use EmbeddableContent\Flow\SourceFlowService;
use EmbeddableContent\Flow\StatementGuidAssigner;
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
 * Create (no qid): class + title (+ required authors/parent for the classes
 * that demand them) → new item, classic page when the class has one.
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

	public function __construct(
		$mainModule,
		$moduleName,
		SourceFlowService $flow,
		ClassicPageCreator $pageCreator,
		EmbeddableContentConfig $config
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->flow = $flow;
		$this->pageCreator = $pageCreator;
		$this->config = $config;
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
			if ( $params[$field] !== null && $params[$field] !== '' ) {
				$record[$field] = $params[$field];
			}
		}

		$error = $this->flow->prepare( $classKey, $record, $creating );
		if ( $error !== null ) {
			$this->dieWithError( new RawMessage( $error ), 'invalid_input' );
		}

		$user = $this->getUser();
		$summary = $params['summary'];
		$store = WikibaseRepo::getEntityStore();
		$confirmDuplicate = !empty( $params['confirmDuplicate'] );

		if ( $creating ) {
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
					$this->flow->labelFor( $classKey, $record ),
					$classId !== null ? [ $classId ] : []
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
			$item = $this->flow->buildItem( $classKey, $record );
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
			$pageSpec = $this->flow->pageSpecFor( $classKey );
			if ( $pageSpec !== null ) {
				$pageTitle = $this->pageCreator->createFor(
					$pageSpec,
					$this->flow->labelFor( $classKey, $record ),
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

		$this->getResult()->addValue( null, 'source', $result );
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
