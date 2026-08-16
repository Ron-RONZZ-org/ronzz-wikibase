<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- entry point name

declare( strict_types = 1 );

namespace EmbeddableContent\Maintenance;

use DataValues\StringValue;
use EmbeddableContent\Manifest\ClassManifestRow;
use EmbeddableContent\Manifest\LanguageManifestRow;
use EmbeddableContent\Manifest\ManifestException;
use EmbeddableContent\Manifest\ManifestReader;
use EmbeddableContent\Manifest\PropertyManifestRow;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\User\User;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Lib\Store\MatchingTermsLookup;
use Wikibase\Lib\TermIndexEntry;
use Wikibase\Repo\WikibaseRepo;

/**
 * Imports the EmbeddableContent vocabulary manifests into a Wikibase instance.
 *
 * Runs per entity type (`--type=property|class|language`) and works in two
 * phases:
 * - Phase A: create entities with labels/descriptions only. Entities whose
 *   primary-language label already exists are skipped (`skip-existing-label`),
 *   which makes the import idempotent and resume-safe.
 * - Phase B: add the alignment statements (`equivalent property` /
 *   `equivalent class`, and `instance of → programming language` for language
 *   items) once all referenced entities exist.
 *
 * No entity IDs appear anywhere: everything is resolved by label at run time.
 *
 * Usage: php maintenance/run.php extensions/EmbeddableContent/maintenance/importVocabulary.php --type=property
 *
 * @license GPL-2.0-or-later
 */
class ImportVocabulary extends Maintenance {

	private const MANIFESTS = [
		'property' => 'properties.csv',
		'class' => 'classes.csv',
		'language' => 'languages.csv',
	];

	/** Language in which the alignment anchor labels ("equivalent property", ...) are written. */
	private const ANCHOR_LANGUAGE = 'en';

	/** @var string */
	private $extensionDir;

	/** @var string */
	private $lang;

	/** @var bool */
	private $dryRun;

	/** @var bool */
	private $strict;

	/** @var MatchingTermsLookup */
	private $termLookup;

	/** @var int[] */
	private $statistics = [
		'created' => 0,
		'skipped' => 0,
		'aligned' => 0,
		'errors' => 0,
	];

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Import the EmbeddableContent vocabulary manifests (properties, classes, languages) into a Wikibase instance.'
		);
		$this->addOption( 'type', 'Entity type to import: property, class or language', true, true, true );
		$this->addOption( 'file', 'Manifest CSV path (defaults to the bundled manifest for the type)', false, true );
		$this->addOption( 'lang', 'Language for the label checks (default: first language in the manifest)', false, true );
		$this->addOption( 'dry-run', 'Validate and report without writing' );
		$this->addOption( 'strict', 'Fail instead of skipping when the label already exists' );
		$this->addOption( 'user', 'User to attribute the edits to (default: the maintenance script system user)', false, true );

		$this->extensionDir = dirname( __DIR__ );
	}

	public function execute() {
		$this->dryRun = (bool)$this->getOption( 'dry-run' );
		$this->strict = (bool)$this->getOption( 'strict' );
		$this->termLookup = WikibaseRepo::getMatchingTermsLookupFactory()
			->getLookupForSource( WikibaseRepo::getLocalEntitySource() );

		$types = (array)$this->getOption( 'type' );
		foreach ( $types as $type ) {
			$this->importType( $type );
		}

		$this->output(
			sprintf(
				"Import complete: %d created, %d skipped, %d aligned, %d errors\n",
				$this->statistics['created'],
				$this->statistics['skipped'],
				$this->statistics['aligned'],
				$this->statistics['errors']
			)
		);
		if ( $this->statistics['errors'] > 0 ) {
			$this->fatalError( 'Import finished with errors; see above.' );
		}
	}

	private function importType( string $type ): void {
		if ( !isset( self::MANIFESTS[$type] ) ) {
			$this->error( "Unknown --type \"$type\" (allowed: property, class, language)" );
			$this->statistics['errors']++;
			return;
		}

		$path = $this->getOption( 'file' ) ?? $this->extensionDir . '/manifests/' . self::MANIFESTS[$type];

		try {
			$reader = new ManifestReader();
			switch ( $type ) {
				case 'property':
					$rows = $reader->readProperties( $path );
					$this->lang = $this->getOption( 'lang' ) ?? $this->firstLanguageOf( $path );
					foreach ( $rows as $row ) {
						$this->importPropertyRow( $row );
					}
					break;
				case 'class':
					$rows = $reader->readClasses( $path );
					$this->lang = $this->getOption( 'lang' ) ?? $this->firstLanguageOf( $path );
					foreach ( $rows as $row ) {
						$this->importClassRow( $row );
					}
					break;
				case 'language':
					$rows = $reader->readLanguages( $path );
					$this->lang = $this->getOption( 'lang' ) ?? $this->firstLanguageOf( $path );
					foreach ( $rows as $row ) {
						$this->importLanguageRow( $row );
					}
					break;
			}
		} catch ( ManifestException $e ) {
			$this->error( $e->getMessage() );
			$this->statistics['errors']++;
		}
	}

	private function importPropertyRow( PropertyManifestRow $row ): void {
		$label = $this->primaryLabel( $row->getLabels() );
		$existingId = $this->findEntityIdByLabel( $label, Property::ENTITY_TYPE );

		if ( $existingId !== null ) {
			$this->handleExisting( $label, Property::ENTITY_TYPE, $existingId );
			return;
		}

		$property = Property::newFromType( $row->getDatatype() );
		$this->applyTerms( $property, $row->getLabels(), $row->getDescriptions() );
		if ( !$this->saveNew( $property, $label ) ) {
			return;
		}

		$this->alignProperty( $property, $row->getAlignUri(), $row->getAlignWikidata() );
	}

	private function importClassRow( ClassManifestRow $row ): void {
		$label = $this->primaryLabel( $row->getLabels() );
		$existingId = $this->findEntityIdByLabel( $label, Item::ENTITY_TYPE );

		if ( $existingId !== null ) {
			$this->handleExisting( $label, Item::ENTITY_TYPE, $existingId );
			return;
		}

		$item = new Item();
		$this->applyTerms( $item, $row->getLabels(), $row->getDescriptions() );
		if ( !$this->saveNew( $item, $label ) ) {
			return;
		}

		$this->alignClass( $item, $row->getAlignUri(), $row->getAlignWikidata() );
	}

	private function importLanguageRow( LanguageManifestRow $row ): void {
		$label = $this->primaryLabel( $row->getLabels() );
		$existingId = $this->findEntityIdByLabel( $label, Item::ENTITY_TYPE );

		if ( $existingId !== null ) {
			$this->handleExisting( $label, Item::ENTITY_TYPE, $existingId );
			return;
		}

		$item = new Item();
		$this->applyTerms( $item, $row->getLabels(), $row->getDescriptions() );
		if ( !$this->saveNew( $item, $label ) ) {
			return;
		}

		$this->alignLanguage( $item, $row->getWikidataQid(), $label );
	}

	private function alignProperty( Property $property, ?string $alignUri, ?string $alignWikidata ): void {
		$equivalentPropertyId = $this->findEntityIdByLabel( 'equivalent property', Property::ENTITY_TYPE, self::ANCHOR_LANGUAGE );
		$additions = [];
		foreach ( [ $alignUri, $alignWikidata ] as $uri ) {
			if ( $uri !== null ) {
				$additions[] = $uri;
			}
		}

		if ( $equivalentPropertyId === null || $additions === [] ) {
			return; // no alignment configured yet — fine, property stays unaligned
		}

		foreach ( $additions as $uri ) {
			$property->getStatements()->addNewStatement(
				new PropertyValueSnak( $equivalentPropertyId, new StringValue( $uri ) ),
				null,
				null,
				( new GuidGenerator() )->newGuid( $property->getId() )
			);
		}
		$this->saveUpdate( $property, 'Align property with equivalent property' );
	}

	private function alignClass( Item $item, ?string $alignUri, ?string $alignWikidata ): void {
		if ( !$this->addEquivalentClassStatements( $item, $alignUri, $alignWikidata ) ) {
			return;
		}
		$this->saveUpdate( $item, 'Align class with equivalent class' );
	}

	private function alignLanguage( Item $item, ?string $wikidataQid, string $label ): void {
		$instanceOfId = $this->findEntityIdByLabel( 'instance of', Property::ENTITY_TYPE, self::ANCHOR_LANGUAGE );
		$programmingLanguageClassId = $this->findEntityIdByLabel( 'programming language', Item::ENTITY_TYPE, self::ANCHOR_LANGUAGE );

		if ( $instanceOfId === null || $programmingLanguageClassId === null ) {
			$this->error(
				sprintf( 'Cannot classify language item "%s": instance-of/programming-language vocabulary missing', $label )
			);
			$this->statistics['errors']++;
			return;
		}

		$item->getStatements()->addNewStatement(
			new PropertyValueSnak( $instanceOfId, new EntityIdValue( $programmingLanguageClassId ) ),
			null,
			null,
			( new GuidGenerator() )->newGuid( $item->getId() )
		);

		if ( $wikidataQid !== null ) {
			$this->addEquivalentClassStatements( $item, null, 'https://www.wikidata.org/wiki/' . $wikidataQid );
		}

		$this->saveUpdate( $item, 'Classify language item' );
	}

	/**
	 * Adds `equivalent class` statements for the given target URIs, if the
	 * property and any targets are available. Does not save.
	 */
	private function addEquivalentClassStatements( Item $item, ?string $alignUri, ?string $alignWikidata ): bool {
		$equivalentClassId = $this->findEntityIdByLabel( 'equivalent class', Property::ENTITY_TYPE, self::ANCHOR_LANGUAGE );
		$additions = [];
		foreach ( [ $alignUri, $alignWikidata ] as $uri ) {
			if ( $uri !== null ) {
				$additions[] = $uri;
			}
		}

		if ( $equivalentClassId === null || $additions === [] ) {
			return false;
		}

		foreach ( $additions as $uri ) {
			$item->getStatements()->addNewStatement(
				new PropertyValueSnak( $equivalentClassId, new StringValue( $uri ) ),
				null,
				null,
				( new GuidGenerator() )->newGuid( $item->getId() )
			);
		}
		return true;
	}

	private function applyTerms( EntityDocument $entity, array $labels, array $descriptions ): void {
		foreach ( $labels as $language => $label ) {
			$entity->setLabel( $language, $label );
		}
		foreach ( $descriptions as $language => $description ) {
			$entity->setDescription( $language, $description );
		}
	}

	/**
	 * Returns the primary-language label, failing hard when --lang is not a
	 * language the manifest carries.
	 *
	 * @param array<string,string> $labels
	 */
	private function primaryLabel( array $labels ): string {
		if ( !isset( $labels[$this->lang] ) ) {
			$this->fatalError(
				sprintf( '--lang=%s is not a language of this manifest (available: %s)', $this->lang, implode( ', ', array_keys( $labels ) ) )
			);
		}
		return $labels[$this->lang];
	}

	/**
	 * Saves a brand-new entity. Returns false (after skipping or failing) when
	 * nothing was created.
	 */
	private function saveNew( EntityDocument $entity, string $label ): bool {
		if ( $this->dryRun ) {
			$this->output( "DRY-RUN: would create $label (" . $entity->getType() . ")\n" );
			$this->statistics['created']++;
			return false;
		}

		try {
			WikibaseRepo::getEntityStore()->saveEntity( $entity, 'Import vocabulary manifest (EmbeddableContent)', $this->getUser(), EDIT_NEW );
			$this->statistics['created']++;
			$this->output( "created $label (" . $entity->getId()->getSerialization() . ")\n" );
			return true;
		} catch ( \Exception $e ) {
			$this->error( sprintf( 'Failed to create "%s": %s', $label, $e->getMessage() ) );
			$this->statistics['errors']++;
			return false;
		}
	}

	private function saveUpdate( EntityDocument $entity, string $summary ): void {
		if ( $this->dryRun ) {
			$this->output( "DRY-RUN: would $summary for " . $entity->getId()->getSerialization() . "\n" );
			$this->statistics['aligned']++;
			return;
		}

		try {
			WikibaseRepo::getEntityStore()->saveEntity( $entity, $summary, $this->getUser(), EDIT_UPDATE );
			$this->statistics['aligned']++;
		} catch ( \Exception $e ) {
			$this->error(
				sprintf( 'Failed to %s for "%s": %s', $summary, $entity->getId()->getSerialization(), $e->getMessage() )
			);
			$this->statistics['errors']++;
		}
	}

	private function handleExisting( string $label, string $entityType, EntityId $id ): void {
		if ( $this->strict ) {
			$this->fatalError( sprintf( '--strict: "%s" (%s) already exists as %s', $label, $entityType, $id->getSerialization() ) );
		}
		$this->output( "skipped $label (" . $id->getSerialization() . " already exists)\n" );
		$this->statistics['skipped']++;
	}

	/**
	 * Resolves a label to an entity id via the term store.
	 */
	private function findEntityIdByLabel( string $label, string $entityType, ?string $language = null ): ?EntityId {
		$language = $language ?? $this->lang;
		$entries = $this->termLookup->getMatchingTerms(
			$label,
			$entityType,
			$language,
			TermIndexEntry::TYPE_LABEL,
			[ 'caseSensitive' => false ]
		);
		foreach ( $entries as $entry ) {
			return $entry->getEntityId();
		}
		return null;
	}

	private function firstLanguageOf( string $path ): string {
		// Column order is stable per manifest; reading just the header is enough.
		$handle = fopen( $path, 'rb' );
		if ( $handle === false ) {
			$this->fatalError( "Cannot open manifest \"$path\"" );
		}
		$header = fgetcsv( $handle, 0, ',', '"', '\\' );
		fclose( $handle );
		if ( $header === false ) {
			$this->fatalError( "Cannot read header of \"$path\"" );
		}
		foreach ( $header as $column ) {
			if ( preg_match( '/^label\.([a-z-]+)$/', (string)$column, $m ) === 1 ) {
				return $m[1];
			}
		}
		$this->fatalError( "No label.<lang> column in \"$path\"" );
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

return ImportVocabulary::class;
