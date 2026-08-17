<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Fetch\ProviderClient;
use EmbeddableContent\Fetch\ProviderResult;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\Rdbms\DBError;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\Lib\TermIndexEntry;
use Wikibase\Repo\WikibaseRepo;

/**
 * Base class for the issue-#7 entity-creation pages: fetch from external
 * authorities (import-on-reference), preview/select, create-or-skip the local
 * stub item.
 *
 * Two-step flow (token in the session, subpage carries the token):
 *   1. search  — kind-specific inputs → ProviderClient → candidates stored
 *                in the session under the token → redirect to /<token>
 *   2. select  — radio over candidates + class picker → create-or-skip →
 *                redirect to the created (or existing) item
 *
 * Imported statements carry authority IDs (externalIds), citation metadata
 * (citationMetadata) and an import-provenance reference (source URL + date),
 * all written at normal rank; the item is created with the English label
 * (fr/eo are editor additions).
 *
 * @license GPL-2.0-or-later
 */
abstract class SpecialAddExternalEntity extends SpecialPage {

	private const SESSION_PREFIX = 'extadd:';

	/** @var EmbeddableContentConfig */
	protected $config;

	/** @var ProviderClient */
	protected $client;

	public function __construct(
		string $pageName,
		EmbeddableContentConfig $config,
		ProviderClient $client
	) {
		parent::__construct( $pageName );
		$this->config = $config;
		$this->client = $client;
	}

	/** Canonical kind: person | source | collective */
	abstract protected function kindKey(): string;

	/** @return array<string,mixed> HTMLForm field descriptors for the search step */
	abstract protected function buildSearchFields(): array;

	/** Runs the provider cascade for the search-step inputs. */
	abstract protected function search( array $data ): ProviderResult;

	/** @return array<string,string> candidate option label (radio) => record index */
	abstract protected function candidateOptions( array $records ): array;

	/**
	 * Creates (or reuses) the local item for the selected record.
	 * Returns the item id.
	 */
	abstract protected function createFromRecord( array $record, string $classItemId ): string;

	/**
	 * Class options for the selection step: label => item id.
	 *
	 * @return array<string,string>
	 */
	abstract protected function classOptions(): array;

	/** Default class item id for the selection step (harvest inference). */
	abstract protected function defaultClassItemId( array $record ): ?string;

	public function execute( $subPage ) {
		// The search step performs server-side external fetches — anonymous
		// users must not be able to trigger them (abuse/rate-limit surface).
		$this->requireLogin();
		$this->getOutput()->enableOOUI();
		$token = trim( (string)$subPage );
		if ( $token !== '' ) {
			$this->executeSelection( $token );
			return;
		}
		$this->executeSearch();
	}

	// ------------------------------------------------------------- step 1

	private function executeSearch(): void {
		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-' . $this->kindKey() . '-title' )->text() );
		$form = HTMLForm::factory( 'ooui', $this->buildSearchFields(), $this->getContext() );
		$form->setTitle( $this->getPageTitle() )
			->setSubmitTextMsg( 'embeddablecontent-extsearch-submit' )
			->setSubmitCallback( [ $this, 'onSearchSubmit' ] )
			->setSubmitID( 'wb-ext-add-search' )
			->setWrapperLegendMsg( 'embeddablecontent-extsearch-legend' );
		$form->show();
	}

	/**
	 * @param array<string,mixed> $data
	 * @return bool
	 */
	public function onSearchSubmit( array $data ) {
		try {
			$result = $this->search( $data );
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-extsearch-error', get_class( $e ) )->text();
		}

		foreach ( $result->warnings as $warning ) {
			$this->getOutput()->addHTML(
				\MediaWiki\Html\Html::warningBox( htmlspecialchars( $warning ), 'embeddablecontent-warning' )
			);
		}

		if ( $result->records === [] ) {
			return $this->msg( 'embeddablecontent-extsearch-nohits' )->text();
		}

		$token = \MWCryptRand::generateHex( 16 );
		// Store plain arrays (records are value objects; the session must be
		// serializable and the selection step must not depend on classes).
		$records = array_map(
			static fn ( $record ): array => json_decode( json_encode( $record ), true ),
			$result->records
		);
		$this->getRequest()->getSession()->set( self::SESSION_PREFIX . $token, $records );
		$this->getOutput()->redirect( $this->getPageTitle( $token )->getFullURL() );
		return true;
	}

	// ------------------------------------------------------------- step 2

	private function executeSelection( string $token ): void {
		$session = $this->getRequest()->getSession();
		$records = $session->get( self::SESSION_PREFIX . $token );
		if ( !is_array( $records ) || $records === [] ) {
			$this->getOutput()->addHTML(
				\MediaWiki\Html\Html::errorBox(
					$this->msg( 'embeddablecontent-extselect-expired' )->escaped()
					. ' <a href="' . htmlspecialchars( $this->getPageTitle()->getFullURL() ) . '">'
					. $this->msg( 'embeddablecontent-extselect-retry' )->escaped() . '</a>'
				)
			);
			return;
		}

		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-' . $this->kindKey() . '-select-title' )->text() );
		$firstRecord = $records[0] ?? [];

		$form = HTMLForm::factory( 'ooui', [
			'candidates' => [
				'type' => 'radio',
				'label-message' => 'embeddablecontent-extselect-candidates',
				'options' => $this->candidateOptions( $records ),
				'default' => '0',
				'required' => true,
			],
			'class' => [
				'type' => 'select',
				'label-message' => 'embeddablecontent-extselect-class',
				'options' => $this->classOptions(),
				'default' => $this->defaultClassItemId( $firstRecord ) ?? '',
				'required' => true,
			],
		], $this->getContext() );

		$form->setTitle( $this->getPageTitle( $token ) )
			->setSubmitTextMsg( 'embeddablecontent-extselect-create' )
			->setSubmitCallback( fn ( array $data ) => $this->onSelectSubmit( $data, $token, $records ) )
			->setSubmitID( 'wb-ext-add-create' )
			->setWrapperLegendMsg( 'embeddablecontent-extselect-legend' );
		$form->show();
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<int,array<string,mixed>> $records
	 * @return bool|string
	 */
	public function onSelectSubmit( array $data, string $token, array $records ) {
		$index = (int)( $data['candidates'] ?? -1 );
		$record = $records[$index] ?? null;
		if ( $record === null || !is_array( $record ) ) {
			return $this->msg( 'embeddablecontent-extselect-invalid' )->text();
		}
		$classItemId = (string)( $data['class'] ?? '' );
		if ( $classItemId === '' ) {
			return $this->msg( 'embeddablecontent-extselect-classrequired' )->text();
		}

		try {
			$itemId = $this->createFromRecord( $record, $classItemId );
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-extcreate-error', get_class( $e ), $e->getMessage() )->text();
		}

		$this->getRequest()->getSession()->remove( self::SESSION_PREFIX . $token );
		$this->getOutput()->redirect(
			WikibaseRepo::getEntityTitleStoreLookup()->getTitleForId( new ItemId( $itemId ) )->getFullURL()
		);
		return true;
	}

	// ------------------------------------------------------------- shared

	/**
	 * Create-or-skip: reuses an existing local item with the same primary
	 * label (seed semantics), otherwise creates it with the given statements.
	 *
	 * @param array<string,mixed> $statementSpecs property id => DataValue
	 */
	protected function createOrSkipItem( string $label, string $classItemId, array $statementSpecs, array $record ): string {
		$existing = $this->findItemIdByLabel( $label );
		if ( $existing !== null ) {
			return $existing;
		}

		$item = new Item();
		$item->setLabel( 'en', $label );

		WikibaseRepo::getEntityStore()->saveEntity(
			$item,
			$this->msg( 'embeddablecontent-extcreate-edit-summary', $label )->inContentLanguage()->text(),
			$this->getUser(),
			EDIT_NEW
		);

		$guidGenerator = new GuidGenerator();
		$add = function ( $propertyId, $value, $reference = true ) use ( $item, $guidGenerator, $record ): void {
			// NOTE: Statement::__construct is (mainSnak, qualifiers, references, guid).
			$statement = new Statement(
				new PropertyValueSnak( new \Wikibase\DataModel\Entity\NumericPropertyId( $propertyId ), $value ),
				null,
				null,
				$guidGenerator->newGuid( $item->getId() )
			);
			if ( $reference ) {
				$referenceSnaks = $this->importReferenceSnaks( $record );
				if ( $referenceSnaks !== null ) {
					$statement->addNewReference( $referenceSnaks );
				}
			}
			$item->getStatements()->addStatement( $statement );
		};

		$add( $this->config->instanceOfPropertyId(), new EntityIdValue( new ItemId( $classItemId ) ), false );
		foreach ( $statementSpecs as $propertyId => $value ) {
			$add( $propertyId, $value );
		}

		WikibaseRepo::getEntityStore()->saveEntity(
			$item,
			$this->msg( 'embeddablecontent-extcreate-edit-summary', $label )->inContentLanguage()->text(),
			$this->getUser(),
			EDIT_UPDATE
		);
		return $item->getId()->getSerialization();
	}

	/**
	 * Import-provenance reference: source URL → authority URL, date → today.
	 */
	private function importReferenceSnaks( array $record ): ?SnakList {
		$provenance = $this->config->provenancePropertyIds();
		$url = $this->authorityUrl( $record );
		if ( $url === null || !isset( $provenance['sourceUrl'], $provenance['date'] ) ) {
			return null;
		}
		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return new SnakList( [
			new PropertyValueSnak(
				new \Wikibase\DataModel\Entity\NumericPropertyId( $provenance['sourceUrl'] ),
				new StringValue( $url )
			),
			new PropertyValueSnak(
				new \Wikibase\DataModel\Entity\NumericPropertyId( $provenance['date'] ),
				new TimeValue(
					'+' . $now->format( 'Y-m-d' ) . 'T00:00:00Z',
					0, 0, 0,
					TimeValue::PRECISION_DAY,
					'http://www.wikidata.org/entity/Q1985727'
				)
			),
		] );
	}

	/**
	 * Authority URL for the import-provenance reference, derived from the
	 * record's provider + identifiers.
	 */
	private function authorityUrl( array $record ): ?string {
		switch ( $record['provider'] ?? '' ) {
			case 'wikidata':
				return isset( $record['wikidataId'] ) ? 'https://www.wikidata.org/wiki/' . $record['wikidataId'] : null;
			case 'orcid':
				return isset( $record['orcid'] ) ? 'https://orcid.org/' . $record['orcid'] : null;
			case 'openalex':
				return isset( $record['providerId'] ) ? $record['providerId'] : null;
			case 'crossref':
				return isset( $record['doi'] ) ? 'https://doi.org/' . $record['doi'] : null;
			case 'openlibrary':
				return isset( $record['providerId'] ) ? 'https://openlibrary.org' . $record['providerId'] : null;
			case 'dblp':
				return isset( $record['providerId'] ) ? $record['providerId'] : null;
			default:
				return null;
		}
	}

	/**
	 * ExternalId statements for the record: canonical key => record field.
	 *
	 * @return array<string,string> externalIds key => record field name
	 */
	protected function externalIdRecordMap(): array {
		return [
			'wikidata' => 'wikidataId',
			'orcid' => 'orcid',
			'viaf' => 'viafId',
			'isni' => 'isni',
			'doi' => 'doi',
			'isbn' => 'isbn',
			'openalex' => 'openalexId',
			'pubmed' => 'pubmedId',
		];
	}

	/**
	 * Builds the authority-ID statement specs present in the config map.
	 *
	 * @return array<string,\Wikibase\DataModel\DataValue> property id => DataValue
	 */
	protected function externalIdStatements( array $record ): array {
		$specs = [];
		foreach ( $this->externalIdRecordMap() as $key => $field ) {
			$propertyId = $this->config->externalIdPropertyIds()[$key] ?? null;
			if ( $propertyId === null || empty( $record[$field] ) ) {
				continue;
			}
			$specs[$propertyId] = new StringValue( (string)$record[$field] );
		}
		return $specs;
	}

	/**
	 * Builds the citation-metadata statement specs present in the config map.
	 *
	 * @return array<string,\Wikibase\DataModel\DataValue>
	 */
	protected function citationMetadataStatements( array $record ): array {
		$specs = [];
		$map = [
			'givenName' => 'givenName',
			'familyName' => 'familyName',
			'publishedIn' => 'containerTitle',
			'publisher' => 'publisher',
			'pages' => 'pages',
			'volume' => 'volume',
			'issue' => 'issue',
		];
		foreach ( $map as $key => $field ) {
			$propertyId = $this->config->citationMetadataPropertyIds()[$key] ?? null;
			if ( $propertyId === null || empty( $record[$field] ) ) {
				continue;
			}
			$specs[$propertyId] = new StringValue( (string)$record[$field] );
		}
		return $specs;
	}

	private function findItemIdByLabel( string $label ): ?string {
		try {
			$entries = WikibaseRepo::getMatchingTermsLookupFactory()
				->getLookupForSource( WikibaseRepo::getLocalEntitySource() )
				->getMatchingTerms(
					$label,
					Item::ENTITY_TYPE,
					'en',
					TermIndexEntry::TYPE_LABEL,
					[ 'caseSensitive' => false ]
				);
		} catch ( DBError $e ) {
			return null;
		}
		foreach ( $entries as $entry ) {
			$id = $entry->getEntityId();
			if ( $id instanceof ItemId ) {
				return $id->getSerialization();
			}
		}
		return null;
	}

	/** @return string[] */
	protected function recordSummary( array $record ): array {
		$bits = [];
		foreach ( [ 'wikidataId', 'orcid', 'viafId', 'doi', 'isbn', 'openalexId', 'pubmedId' ] as $field ) {
			if ( !empty( $record[$field] ) ) {
				$bits[] = (string)$record[$field];
			}
		}
		return $bits;
	}

	protected function getGroupName(): string {
		return 'wikibase';
	}
}
