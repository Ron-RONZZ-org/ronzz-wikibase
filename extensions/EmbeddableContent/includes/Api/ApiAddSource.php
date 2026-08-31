<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\Flow\ClassicPageCreator;
use EmbeddableContent\Flow\SourceFlowService;
use MediaWiki\Api\ApiBase;
use MediaWiki\Message\RawMessage;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
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

	public function __construct( $mainModule, $moduleName, SourceFlowService $flow, ClassicPageCreator $pageCreator ) {
		parent::__construct( $mainModule, $moduleName );
		$this->flow = $flow;
		$this->pageCreator = $pageCreator;
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

		if ( $creating ) {
			$item = $this->flow->buildItem( $classKey, $record );
			$revision = $store->saveEntity(
				$item,
				$summary ?? 'Add source item',
				$user,
				EDIT_NEW
			);
			$itemId = $item->getId()->getSerialization();

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
		return [
			'title', 'description', 'authors', 'publisher', 'journal', 'volume', 'issue',
			'pages', 'chapters', 'year', 'isbn', 'doi', 'wikidataId', 'openalexWorkId',
			'pubmedId', 'url', 'duration', 'youtubeChannelId', 'youtubeVideoId', 'accessUrl', 'parent',
		];
	}
}
