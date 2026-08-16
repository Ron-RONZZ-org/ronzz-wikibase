<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- entry point name

declare( strict_types = 1 );

namespace WikibaseCitation\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\Property;
use Wikibase\Lib\Store\MatchingTermsLookup;
use Wikibase\Lib\Store\TermIndexEntry;
use Wikibase\Repo\WikibaseRepo;
use WikibaseCitation\Manifest\CitationMapException;
use WikibaseCitation\Manifest\CitationMapReader;

/**
 * Publishes the WikibaseCitation maps (CSL field => property, class => CSL
 * type) as admin-editable MediaWiki: pages.
 *
 * The manifests reference the seeded vocabulary by label; this script resolves
 * the labels to entity ids via the term store and writes the resolved maps to
 * `MediaWiki:Citation-property-map.json` and `MediaWiki:Citation-type-map.json`
 * (the pages the citation API reads at request time).
 *
 * Unresolved labels are skipped with a warning (graceful degradation — missing
 * fields are simply omitted, never fatal), unless --strict is given.
 *
 * Usage: php maintenance/run.php extensions/WikibaseCitation/maintenance/importCitationMap.php
 *
 * @license GPL-2.0-or-later
 */
class ImportCitationMap extends Maintenance {

	/** Language in which the manifest labels are written. */
	private const ANCHOR_LANGUAGE = 'en';

	private const DEFAULT_PROPERTY_MAP_PAGE = 'MediaWiki:Citation-property-map.json';
	private const DEFAULT_TYPE_MAP_PAGE = 'MediaWiki:Citation-type-map.json';

	/** @var string */
	private $extensionDir;

	/** @var bool */
	private $dryRun;

	/** @var bool */
	private $force;

	/** @var bool */
	private $strict;

	/** @var MatchingTermsLookup */
	private $termLookup;

	/** @var int[] */
	private $statistics = [
		'published' => 0,
		'skippedPages' => 0,
		'unresolved' => 0,
	];

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Publish the WikibaseCitation maps (CSL field => property, class => CSL type) as MediaWiki: pages.'
		);
		$this->addOption( 'property-map', 'Property map manifest path (default: the bundled manifest)', false, true );
		$this->addOption( 'type-map', 'Type map manifest path (default: the bundled manifest)', false, true );
		$this->addOption( 'page-property-map', 'Page title for the resolved property map', false, true );
		$this->addOption( 'page-type-map', 'Page title for the resolved type map', false, true );
		$this->addOption( 'dry-run', 'Resolve and print the maps without writing' );
		$this->addOption( 'force', 'Overwrite an existing page' );
		$this->addOption( 'strict', 'Fail on unresolved labels instead of skipping them' );
		$this->addOption( 'user', 'User to attribute the edits to (default: the maintenance script system user)', false, true );

		$this->extensionDir = dirname( __DIR__ );
	}

	public function execute() {
		$this->dryRun = (bool)$this->getOption( 'dry-run' );
		$this->force = (bool)$this->getOption( 'force' );
		$this->strict = (bool)$this->getOption( 'strict' );
		$this->termLookup = WikibaseRepo::getMatchingTermsLookupFactory()
			->getLookupForSource( WikibaseRepo::getLocalEntitySource() );

		$reader = new CitationMapReader();
		try {
			$propertyMap = $reader->readPropertyMap(
				$this->getOption( 'property-map' ) ?? $this->extensionDir . '/manifests/citation-property-map.json'
			);
			$typeMap = $reader->readTypeMap(
				$this->getOption( 'type-map' ) ?? $this->extensionDir . '/manifests/citation-type-map.json'
			);
		} catch ( CitationMapException $e ) {
			$this->fatalError( $e->getMessage() );
		}

		$this->publishMap(
			$this->getOption( 'page-property-map' ) ?? self::DEFAULT_PROPERTY_MAP_PAGE,
			$this->resolvePropertyMap( $propertyMap )
		);
		$this->publishMap(
			$this->getOption( 'page-type-map' ) ?? self::DEFAULT_TYPE_MAP_PAGE,
			$this->resolveTypeMap( $typeMap )
		);

		$this->output(
			sprintf(
				"Import complete: %d published, %d pages skipped, %d unresolved entries\n",
				$this->statistics['published'],
				$this->statistics['skippedPages'],
				$this->statistics['unresolved']
			)
		);
		if ( $this->statistics['unresolved'] > 0 && $this->strict ) {
			$this->fatalError( '--strict: unresolved labels, see above.' );
		}
	}

	/**
	 * Resolves CSL field => property label into CSL field => property id.
	 *
	 * @param array<string,string> $map
	 *
	 * @return array<string,string>
	 */
	private function resolvePropertyMap( array $map ): array {
		$resolved = [];
		foreach ( $map as $field => $label ) {
			$id = $this->findEntityIdByLabel( $label, Property::ENTITY_TYPE );
			if ( $id === null ) {
				$this->reportUnresolved( $label );
				continue;
			}
			$resolved[$field] = $id->getSerialization();
		}
		return $resolved;
	}

	/**
	 * Resolves class label => CSL type into class id => CSL type.
	 *
	 * @param array<string,string> $map
	 *
	 * @return array<string,string>
	 */
	private function resolveTypeMap( array $map ): array {
		$resolved = [];
		foreach ( $map as $classLabel => $type ) {
			$id = $this->findEntityIdByLabel( $classLabel, Item::ENTITY_TYPE );
			if ( $id === null ) {
				$this->reportUnresolved( $classLabel );
				continue;
			}
			$resolved[$id->getSerialization()] = $type;
		}
		return $resolved;
	}

	/**
	 * @param string $pageName
	 * @param array<string,string> $map
	 */
	private function publishMap( string $pageName, array $map ): void {
		$json = json_encode( $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( $this->dryRun ) {
			$this->output( "DRY-RUN: would publish $pageName:\n$json\n" );
			return;
		}

		$services = MediaWikiServices::getInstance();
		$title = Title::newFromText( $pageName );
		if ( $title === null ) {
			$this->fatalError( "Invalid page title \"$pageName\"" );
		}
		$page = $services->getWikiPageFactory()->newFromTitle( $title );

		if ( $page->exists() && !$this->force ) {
			$this->output( "skipped $pageName (already exists; use --force to overwrite)\n" );
			$this->statistics['skippedPages']++;
			return;
		}

		$content = \ContentHandler::makeContent( $json, $title, CONTENT_MODEL_JSON );
		$status = $page->doUserEditContent(
			$content,
			$this->getUser(),
			'Import citation map manifest (WikibaseCitation)',
			$page->exists() ? EDIT_UPDATE : EDIT_NEW
		);

		if ( !$status->isOK() ) {
			$message = $status->getMessage();
			$this->error( 'Failed to publish ' . $pageName . ': ' . ( $message ? $message->text() : 'unknown error' ) );
			$this->statistics['unresolved']++;
			return;
		}
		$this->statistics['published']++;
		$this->output( "published $pageName\n" );
	}

	private function reportUnresolved( string $label ): void {
		$this->statistics['unresolved']++;
		if ( $this->strict ) {
			$this->error( "Unresolved label \"$label\"" );
		} else {
			$this->output( "skipped unresolved label \"$label\"\n" );
		}
	}

	private function findEntityIdByLabel( string $label, string $entityType ): ?EntityId {
		$entries = $this->termLookup->getMatchingTerms(
			$label,
			$entityType,
			self::ANCHOR_LANGUAGE,
			TermIndexEntry::TYPE_LABEL,
			[ 'caseSensitive' => false ]
		);
		foreach ( $entries as $entry ) {
			return $entry->getEntityId();
		}
		return null;
	}

	private function getUser(): User {
		$userName = $this->getOption( 'user' );
		if ( $userName !== null ) {
			$user = User::newFromName( $userName );
			if ( $user === null || $user->getId() === 0 ) {
				$this->fatalError( "Unknown user \"$userName\"" );
			}
			return $user;
		}
		return User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] );
	}
}

return ImportCitationMap::class;
