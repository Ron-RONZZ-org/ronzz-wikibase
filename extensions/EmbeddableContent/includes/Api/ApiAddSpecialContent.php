<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\SpecialContentFieldMap;
use EmbeddableContent\Flow\SpecialContentFlowService;
use EmbeddableContent\Flow\StatementGuidAssigner;
use MediaWiki\Api\ApiBase;
use MediaWiki\Message\RawMessage;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\Repo\WikibaseRepo;

/**
 * api.php?action=addspecialcontent — the entity-mode Add* special-content
 * flow (quotation / math / code-snippet) for API clients: creates or
 * updates a content item with the same validation, payload handling
 * (math delimiter stripping, backslash-escaping) and statement building as
 * Special:AddQuotation / AddMath / AddCodeSnippet, driven by
 * SpecialContentFieldMap. Content items create no classic page.
 *
 * Create (no qid): kind + label + content (+ attributedTo for quotations).
 * Update (qid): replaces the statements for the fields provided, keeps
 * everything else (no-clobber), never changes the class.
 *
 * @license GPL-2.0-or-later
 */
class ApiAddSpecialContent extends ApiBase {

	/** @var SpecialContentFlowService */
	private $flow;

	/** @var EmbeddableContentConfig */
	private $config;

	public function __construct( $mainModule, $moduleName, SpecialContentFlowService $flow, EmbeddableContentConfig $config ) {
		parent::__construct( $mainModule, $moduleName );
		$this->flow = $flow;
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

		$record = [];
		foreach ( $this->fieldParams() as $field ) {
			if ( $params[$field] !== null && $params[$field] !== '' ) {
				$record[$field] = $params[$field];
			}
		}

		$error = $this->flow->prepare( $kind, $record, $creating );
		if ( $error !== null ) {
			$this->dieWithError( new RawMessage( $error ), 'invalid_input' );
		}

		$user = $this->getUser();
		$summary = $params['summary'];
		$store = WikibaseRepo::getEntityStore();
		$confirmDuplicate = ( $params['confirmDuplicate'] ?? '' ) === '1';

		if ( $creating ) {
			// Duplication guard: an existing item carrying the record's
			// external ids / URLs, or a highly similar label, aborts with a
			// duplicate result (no create) — machine clients decide;
			// confirmDuplicate=1 forces the create.
			if ( !$confirmDuplicate ) {
				$classId = $this->config->classIds()[$kind] ?? null;
				// SpecialContentFlowService has no labelFor — the content
				// label IS the record's label field.
				$duplicate = \EmbeddableContent\Spec\DuplicateChecker::find(
					$this->config,
					$record,
					(string)( $record['label'] ?? '' ),
					$classId !== null ? [ $classId ] : []
				);
				if ( $duplicate !== null ) {
					$this->getResult()->addValue( null, 'content', [
						'duplicate' => '1',
						'duplicateOf' => $duplicate['itemId'],
						'duplicateLabel' => $duplicate['label'],
						'match' => $duplicate['match'],
					] );
					return;
				}
			}
			$item = $this->flow->buildItem( $kind, $record );
			$summaryText = $summary ?? 'Add content item';
			$store->saveEntity( $item, $summaryText, $user, EDIT_NEW );
			// The first save assigns the item id; statements must carry
			// GUIDs or the entity page renders them as empty edit-mode rows
			// for logged-in users (the client matches statements to the DOM
			// by GUID).
			StatementGuidAssigner::ensureGuids( $item, new GuidGenerator() );
			$revision = $store->saveEntity( $item, $summaryText, $user, EDIT_UPDATE );
			$result = [
				'entityId' => $item->getId()->getSerialization(),
				'entityType' => 'item',
				'latestRevisionId' => $revision->getRevisionId(),
				'created' => '1',
			];
		} else {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $qid ) );
			if ( !$entity instanceof Item ) {
				$this->dieWithError( new RawMessage( "Entity \"{$qid}\" not found." ), 'not_found' );
			}
			$this->flow->applyUpdate( $kind, $entity, $record );
			$revision = $store->saveEntity(
				$entity,
				$summary ?? 'Update content item',
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

		$this->getResult()->addValue( null, 'content', $result );
	}

	public function getAllowedParams() {
		$params = [
			'kind' => [
				self::PARAM_TYPE => SpecialContentFieldMap::KINDS,
				self::PARAM_REQUIRED => true,
			],
			'qid' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => false ],
			'summary' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => false ],
			// Force the create past the duplication guard (the default: a
			// duplicate hit returns { duplicate: 1, duplicateOf, match }).
			'confirmDuplicate' => [ self::PARAM_TYPE => 'boolean', self::PARAM_REQUIRED => false ],
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
		return [
			'label', 'content', 'labelLanguage', 'language', 'programmingLanguage',
			'describes', 'implementationOf', 'attributedTo', 'source', 'sourceUrl', 'date',
		];
	}
}
