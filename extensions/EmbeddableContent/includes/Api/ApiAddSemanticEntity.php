<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\Flow\SemanticEntityFieldMap;
use EmbeddableContent\Flow\SemanticEntityFlowService;
use MediaWiki\Api\ApiBase;
use MediaWiki\Message\RawMessage;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * api.php?action=addsemanticentity — the entity-mode Add* semantic-entity
 * flow (person / software / collective / fictional-character / other) for
 * API clients: creates or updates with the same validation, label derivation
 * and statement building as Special:AddPerson / AddSoftware / AddCollective /
 * AddFictionalCharacter, driven by SemanticEntityFieldMap. Portraits and
 * logos (image uploads) stay browser-only; kind=other takes no raw
 * statements (use wbeditentity for those). Semantic items create their
 * classic Person:/Collective: pages through ClassicPageCreator.
 *
 * Create (no qid): kind + the kind's required fields. Update (qid): replaces
 * the statements for the fields provided, keeps everything else
 * (no-clobber), never changes the class.
 *
 * @license GPL-2.0-or-later
 */
class ApiAddSemanticEntity extends ApiBase {

	/** @var SemanticEntityFlowService */
	private $flow;

	/** @var \EmbeddableContent\Flow\ClassicPageCreator */
	private $pageCreator;

	public function __construct(
		$mainModule,
		$moduleName,
		SemanticEntityFlowService $flow,
		\EmbeddableContent\Flow\ClassicPageCreator $pageCreator
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->flow = $flow;
		$this->pageCreator = $pageCreator;
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

		if ( $creating ) {
			$item = $this->flow->buildItem( $kind, $record );
			$revision = $store->saveEntity(
				$item,
				$summary ?? 'Add semantic entity',
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
			$pageTitle = $this->pageTitleFor( $kind, $record, $user );
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
			'label', 'description', 'givenName', 'familyName',
			'dateOfBirth', 'placeOfBirth', 'dateOfDeath', 'placeOfDeath',
			'orcid', 'viafId', 'isni', 'wikidataId', 'openalexAuthorId',
			'officialWebsite', 'developer', 'license', 'programmingLanguage',
			'operatingSystem', 'userInterface', 'hasUse', 'sourceCodeRepository',
			'documentationUrl', 'collectiveClass', 'parentOrganization',
			'presentInWork', 'instanceOf',
		];
	}

	private function pageTitleFor( string $kind, array $record, $user ): ?string {
		// Person → Person: page, collective → Collective: page, software →
		// FOSS: page (the forms' classic-page pattern); fictional-character
		// items create no classic page.
		$specs = [
			'person' => [ 'NS_PERSON', 'Person' ],
			'collective' => [ 'NS_COLLECTIVE', 'Collective' ],
			'software' => [ 'NS_FOSS', 'FOSS' ],
		];
		$pageSpec = $specs[$kind] ?? null;
		if ( $pageSpec === null || !defined( $pageSpec[0] ) ) {
			return null;
		}
		$label = $this->flow->labelFor( $kind, $record );
		$spec = new \EmbeddableContent\Flow\ClassicPageSpec( constant( $pageSpec[0] ), $pageSpec[1] );
		return $this->pageCreator->createFor( $spec, $label, $record, $user );
	}
}
