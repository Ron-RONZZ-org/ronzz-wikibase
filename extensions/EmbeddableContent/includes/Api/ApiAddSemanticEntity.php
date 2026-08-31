<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\SemanticEntityFieldMap;
use EmbeddableContent\Flow\SemanticEntityFlowService;
use EmbeddableContent\Flow\SoftwarePageKind;
use EmbeddableContent\Flow\StatementGuidAssigner;
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
		$confirmDuplicate = ( $params['confirmDuplicate'] ?? '' ) === '1';

		if ( $creating ) {
			// Duplication guard: an existing item carrying the record's
			// external ids / URLs, or a highly similar label, aborts with a
			// duplicate result (no create) — machine clients decide;
			// confirmDuplicate=1 forces the create.
			if ( !$confirmDuplicate ) {
				$duplicate = \EmbeddableContent\Spec\DuplicateChecker::find(
					$this->config,
					$record,
					$this->flow->labelFor( $kind, $record ),
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
			$item = $this->flow->buildItem( $kind, $record );
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
			// Force the create past the duplication guard (the default: a
			// duplicate hit returns { duplicate: 1, duplicateOf, match }).
			'confirmDuplicate' => [ self::PARAM_TYPE => 'boolean', self::PARAM_REQUIRED => false ],
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

	private function pageTitleFor( string $kind, array $record, $user ): ?string {
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
		$label = $this->flow->labelFor( $kind, $record );
		$spec = new \EmbeddableContent\Flow\ClassicPageSpec( constant( $pageSpec[0] ), $pageSpec[1] );
		return $this->pageCreator->createFor( $spec, $label, $record, $user );
	}
}
