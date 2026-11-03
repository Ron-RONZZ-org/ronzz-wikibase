<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\ClassicPageSpec;
use EmbeddableContent\Flow\SemanticEntityFieldMap;
use EmbeddableContent\Flow\SemanticEntityFlowService;
use EmbeddableContent\Flow\SoftwarePageKind;
use EmbeddableContent\Flow\StatementGuidAssigner;
use EmbeddableContent\Spec\CollisionFinder;
use EmbeddableContent\Spec\ItemLabelLookup;
use EmbeddableContent\Spec\LabelSuffix;
use EmbeddableContent\Spec\PageTitle;
use MediaWiki\Api\ApiBase;
use MediaWiki\Message\RawMessage;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\Repo\WikibaseRepo;

/**
 * api.php?action=addsemanticentity — the entity-mode Add* semantic-entity
 * flow (person / software / collective / fictional-character / other) for
 * API clients: creates or updates with the same validation, label derivation
 * and statement building as Special:AddPerson / AddSoftware / AddCollective /
 * AddFictionalCharacter, driven by SemanticEntityFieldMap. Portraits and
 * logos (image uploads) stay browser-only; kind=other takes no raw
 * statements (use wbeditentity for those). Semantic items create their
 * classic Person:/Collective:/FOSS:/Software: pages through
 * ClassicPageCreator.
 *
 * Create (no qid): kind + the kind's required fields. Update (qid): replaces
 * the statements for the fields provided, keeps everything else
 * (no-clobber), never changes the class.
 *
 * kind=software accepts an optional pageKind (foss|software) naming the
 * classic page namespace; the default follows the chosen license (the
 * FOSS: vs Software: split — see SoftwarePageKind).
 *
 * @license GPL-2.0-or-later
 */
class ApiAddSemanticEntity extends ApiBase {

	/** @var SemanticEntityFlowService */
	private $flow;

	/** @var \EmbeddableContent\Flow\ClassicPageCreator */
	private $pageCreator;

	/** @var EmbeddableContentConfig */
	private $config;

	public function __construct(
		$mainModule,
		$moduleName,
		SemanticEntityFlowService $flow,
		\EmbeddableContent\Flow\ClassicPageCreator $pageCreator,
		EmbeddableContentConfig $config
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->flow = $flow;
		$this->pageCreator = $pageCreator;
		$this->config = $config;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$kind = $params['kind'];
		$qid = $params['qid'] !== null ? strtoupper( trim( $params['qid'] ) ) : null;
		if ( $qid !== null && preg_match( '/^Q[1-9]\d*$/', $qid ) !== 1 ) {
			$this->dieWithError( new RawMessage( "qid \"{$qid}\" is not an item ID." ), 'invalid_qid' );
		}
		$creating = $qid === null;

		// pageKind (kind=software only) is a PAGE decision, not a statement
		// field: it rides the flow record so the item's class follows the
		// page kind (FOSS: ↔ FOSS class, Software: ↔ software class).
		$pageKind = $params['pageKind'];
		$record = [];
		foreach ( $this->fieldParams() as $field ) {
			if ( $params[$field] !== null && $params[$field] !== '' ) {
				$record[$field] = $params[$field];
			}
		}
		if ( $kind === 'software' ) {
			$record['pageKind'] = $pageKind !== null
				? $pageKind
				: SoftwarePageKind::defaultFor( $this->config, (string)( $record['license'] ?? '' ) );
		}

		$error = $this->flow->prepare( $kind, $record, $creating );
		if ( $error !== null ) {
			$this->dieWithError( new RawMessage( $error ), 'invalid_input' );
		}

		$user = $this->getUser();
		$summary = $params['summary'];
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
			$label = $this->flow->labelFor( $kind, $record );
			$pageSpec = $this->pageSpecFor( $kind, $record );
			$targetTitle = $pageSpec !== null
				? PageTitle::fromLabel( $label, $pageSpec->namespace )
				: null;
			$labelWithSuffix = null;

			if ( $collisionAction !== null ) {
				$collision = CollisionFinder::find( $label, 'en', $targetTitle );
				if ( $collision === null ) {
					$this->dieWithError(
						new RawMessage( 'No collision found for this label.' ),
						'no_collision'
					);
				}
				if ( $collisionAction === 'reuse' ) {
					$this->reuseCollisionItem( $collision, $kind, $record, $label, $pageSpec, $targetTitle, $summary, $user );
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
					$labelWithSuffix = $label . $this->collisionSuffix( $label, $pageSpec );
					$confirmDuplicate = true;
				}
			} elseif ( $confirmDuplicate ) {
				// "Create anyway" from the duplicate guard: a same-label item
				// gets the next suffix instead of a hard duplicate.
				if ( ItemLabelLookup::findIdByLabel( $label, 'en' ) !== null ) {
					$labelWithSuffix = $label . $this->collisionSuffix( $label, $pageSpec );
				}
			}

			// Duplication guard: an existing item carrying the record's
			// external ids / URLs, or a highly similar label, aborts with a
			// duplicate result (no create) — machine clients decide;
			// confirmDuplicate=1 forces the create.
			if ( !$confirmDuplicate ) {
				$duplicate = \EmbeddableContent\Spec\DuplicateChecker::find(
					$this->config,
					$record,
					$label,
					self::classFilter( $this->config, $kind, $record )
				);
				if ( $duplicate !== null ) {
					$this->getResult()->addValue( null, 'semantic', [
						'duplicate' => '1',
						'duplicateOf' => $duplicate['itemId'],
						'duplicateLabel' => $duplicate['label'],
						'match' => $duplicate['match'],
					] );
					return;
				}
			}

			// Exact-label collision (the class-filtered guard above cannot
			// see an unclassified item). Keep the existing duplicate /
			// link-confirm behaviour for a target page owned by another item:
			// CollisionFinder returns null in that case.
			if ( !$confirmDuplicate ) {
				$collision = CollisionFinder::find( $label, 'en', $targetTitle );
				if ( $collision !== null ) {
					$this->getResult()->addValue( null, 'semantic', [
						'collision' => '1',
						'collisionOf' => $collision['itemId'],
						'collisionLabel' => $collision['label'],
						'currentSitelink' => $collision['currentSitelink'],
						'targetTitle' => $collision['targetTitle'],
					] );
					return;
				}
			}

			$item = $this->flow->buildItem( $kind, $record );
			if ( $labelWithSuffix !== null ) {
				$item->setLabel( 'en', $labelWithSuffix );
			}
			$summaryText = $summary ?? 'Add semantic entity';
			$store->saveEntity( $item, $summaryText, $user, EDIT_NEW );
			// The first save assigns the item id; statements must carry
			// GUIDs or the entity page renders them as empty edit-mode rows
			// for logged-in users (the client matches statements to the DOM
			// by GUID).
			StatementGuidAssigner::ensureGuids( $item, new GuidGenerator() );
			$revision = $store->saveEntity( $item, $summaryText, $user, EDIT_UPDATE );
			$itemId = $item->getId()->getSerialization();
			$result = [
				'entityId' => $itemId,
				'entityType' => 'item',
				'latestRevisionId' => $revision->getRevisionId(),
				'created' => '1',
			];
			$record['itemId'] = $itemId;
			$pageTitle = $this->pageTitleFor( $kind, $record, $user, $labelWithSuffix );
			if ( $pageTitle !== null ) {
				$result['pageTitle'] = $pageTitle;
			}
		} else {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $qid ) );
			if ( !$entity instanceof Item ) {
				$this->dieWithError( new RawMessage( "Entity \"{$qid}\" not found." ), 'not_found' );
			}
			$this->flow->applyUpdate( $kind, $entity, $record );
			$revision = $store->saveEntity(
				$entity,
				$summary ?? 'Update semantic entity',
				$user,
				EDIT_UPDATE
			);
			$result = [
				'entityId' => $entity->getId()->getSerialization(),
				'entityType' => 'item',
				'latestRevisionId' => $revision->getRevisionId(),
				'updated' => '1',
			];
		}

		$this->getResult()->addValue( null, 'semantic', $result );
	}

	public function getAllowedParams() {
		$params = [
			'kind' => [
				self::PARAM_TYPE => SemanticEntityFieldMap::KINDS,
				self::PARAM_REQUIRED => true,
			],
			'qid' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => false ],
			'summary' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => false ],
			// Force the create past the duplication guard (the default: a
			// duplicate hit returns { duplicate: 1, duplicateOf, match }).
			'confirmDuplicate' => [ self::PARAM_TYPE => 'boolean', self::PARAM_REQUIRED => false ],
			// Resolve an exact-label collision: reuse the existing item and
			// move its sitelink, delete it, or create a suffixed duplicate.
			'collisionAction' => [
				self::PARAM_TYPE => [ 'reuse', 'delete', 'duplicate' ],
				self::PARAM_REQUIRED => false,
			],
			// kind=software only: the classic-page namespace (foss|software);
			// absent → the license decides (SoftwarePageKind).
			'pageKind' => [
				self::PARAM_TYPE => [ SoftwarePageKind::FOSS, SoftwarePageKind::SOFTWARE ],
				self::PARAM_REQUIRED => false,
			],
		];
		foreach ( $this->fieldParams() as $field ) {
			$params[$field] = [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => false ];
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

	/** @return string[] */
	private function fieldParams(): array {
		// The single source of truth (the field-map contract) — a hardcoded
		// copy here is exactly how fields drift apart (alias was advertised by
		// action=addsemanticentity-fields but silently dropped by this module).
		// pageKind is a TYPED enum declared explicitly in getAllowedParams, so
		// the map excludes it from the generic string params.
		return SemanticEntityFieldMap::apiParamFields();
	}

	/**
	 * Class filter for the duplicate-guard label match, per kind.
	 *
	 * @param array<string,mixed> $record
	 * @return string[]
	 */
	private static function classFilter( EmbeddableContentConfig $config, string $kind, array $record ): array {
		switch ( $kind ) {
			case 'person':
				$person = $config->agentClasses()['person'] ?? null;
				return $person !== null ? [ $person ] : [];
			case 'collective':
				return array_values( $config->agentClasses() );
			case 'software':
				return array_values( $config->fossClasses() );
			case 'fictional-character':
				return array_values( $config->fictionalCharacterClasses() );
			case 'other':
				$instanceOf = (string)( $record['instanceOf'] ?? '' );
				return $instanceOf !== '' ? [ $instanceOf ] : [];
		}
		return [];
	}

	private function pageSpecFor( string $kind, array $record ): ?ClassicPageSpec {
		// Person → Person: page, collective → Collective: page, software →
		// FOSS: page unless the record's pageKind says Software: (the
		// FOSS:/Software: split, license-driven default + pageKind override);
		// fictional-character items create no classic page.
		$specs = [
			'person' => [ 'NS_PERSON', 'Person' ],
			'collective' => [ 'NS_COLLECTIVE', 'Collective' ],
		];
		if ( $kind === 'software' ) {
			$specs['software'] = ( $record['pageKind'] ?? '' ) === SoftwarePageKind::SOFTWARE
				? [ 'NS_SOFTWARE', 'Software' ]
				: [ 'NS_FOSS', 'FOSS' ];
		}
		$pageSpec = $specs[$kind] ?? null;
		if ( $pageSpec === null || !defined( $pageSpec[0] ) ) {
			return null;
		}
		return new ClassicPageSpec( constant( $pageSpec[0] ), $pageSpec[1] );
	}

	private function pageTitleFor(
		string $kind,
		array $record,
		$user,
		?string $labelOverride = null
	): ?string {
		$spec = $this->pageSpecFor( $kind, $record );
		if ( $spec === null ) {
			return null;
		}
		$label = $labelOverride ?? $this->flow->labelFor( $kind, $record );
		return $this->pageCreator->createFor( $spec, $label, $record, $user );
	}

	/** The next free duplicate suffix, checking both the item and page title. */
	private function collisionSuffix( string $label, ?ClassicPageSpec $pageSpec ): string {
		return LabelSuffix::nextSuffix(
			$label,
			function ( string $suffix ) use ( $label, $pageSpec ): bool {
				$candidate = $label . $suffix;
				if ( ItemLabelLookup::findIdByLabel( $candidate, 'en' ) !== null ) {
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
	 * collisionAction=reuse: apply the record to the existing item, move its
	 * sitelink to the new page, and create the page if needed.
	 *
	 * @param array<string,mixed> $collision
	 * @param array<string,mixed> $record
	 */
	private function reuseCollisionItem(
		array $collision,
		string $kind,
		array $record,
		string $label,
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
		$this->flow->applyUpdate( $kind, $entity, $record );
		$entity->setLabel( 'en', $label );
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
		$this->getResult()->addValue( null, 'semantic', $result );
	}
}
