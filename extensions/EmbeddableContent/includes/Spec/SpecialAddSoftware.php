<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use EmbeddableContent\Content\FragmentSanitizer;
use EmbeddableContent\Fetch\ProviderResult;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Special:AddSoftware — create a FOSS software item + its FOSS:<Name> wiki
 * page from an external authority (Wikidata → GitHub, issue #26).
 *
 * Extends the issue-#7 external-entity flow (search → select → review →
 * create, + /manual) with one extra step: after the item is created, the
 * FOSS: page is written (transcluding Template:FOSS) and sitelinked to the
 * item, so {{#statements:}} on the page renders from the item at view time.
 *
 * Item-typed facts (developer, license, operating system, …) reference
 * EXISTING local items via entity comboboxes — the instance's "properties
 * first, then items" house rule; harvested labels are shown as context in
 * the review step. URL/string facts (website, source repository, version)
 * are written directly from the corrected record.
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddSoftware extends SpecialAddExternalEntity {

	/**
	 * Item-typed FOSS facts written as entity values; each field is an
	 * entity combobox referencing existing local items.
	 */
	private const FOSS_ENTITY_FIELDS = [
		'developer', 'license', 'operatingSystem', 'programmingLanguage',
		'userInterface', 'hasUse',
	];

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( 'AddSoftware', $config, $client );
	}

	public function execute( $subPage ) {
		// Entity comboboxes in the review/manual steps need the autofill
		// module (same wiring as the AddQuotation provenance block).
		$this->getOutput()->addModules( 'ext.embeddableContent.entitysuggest' );
		parent::execute( $subPage );
	}

	protected function kindKey(): string {
		return 'software';
	}

	protected function buildSearchFields(): array {
		return [
			'name' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-name',
				'required' => true,
				'maxlength' => 250,
			],
		];
	}

	protected function search( array $data ): ProviderResult {
		$name = trim( (string)( $data['name'] ?? '' ) );
		if ( $name === '' ) {
			return new ProviderResult( [], [ 'No software name given' ] );
		}
		return $this->client->searchSoftware( $name );
	}

	protected function candidateOptions( array $records ): array {
		return $this->candidateOptionLabels( $records );
	}

	protected function primaryLabel( array $record ): string {
		// The review step lets the author correct the label (e.g. drop an
		// owner/repo prefix from a GitHub candidate).
		return (string)( $record['label'] ?? '' );
	}

	/** @return array<string,string> authority identifiers relevant to software */
	protected function externalIdRecordMap(): array {
		return [
			'wikidata' => 'wikidataId',
		];
	}

	protected function enrichRecord( array $record ): array {
		if ( !empty( $record['harvested'] ) ) {
			return $record;
		}
		// Harvest on pick: Wikidata hub for the full software record.
		if ( !empty( $record['wikidataId'] ) && ( $record['provider'] ?? '' ) === 'wikidata' ) {
			$harvest = $this->client->harvestSoftware( $record['wikidataId'] );
			if ( $harvest->records !== [] ) {
				$record = array_merge( $record, (array)$harvest->records[0] );
			}
		}
		$record['harvested'] = true;
		return $record;
	}

	protected function reviewFieldSpecs( array $record ): array {
		$fields = $this->labelFieldSpec( 'label', 'embeddablecontent-extsearch-name', (string)( $record['label'] ?? '' ) )
			+ $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) )
			+ [
				'website' => [
					'type' => 'url',
					'label-message' => 'embeddablecontent-field-officialwebsite',
					'default' => (string)( $record['website'] ?? '' ),
					'maxlength' => 250,
				],
				'sourceRepository' => [
					'type' => 'url',
					'label-message' => 'embeddablecontent-field-sourcerepository',
					'default' => (string)( $record['sourceRepository'] ?? '' ),
					'maxlength' => 250,
				],
				'softwareVersion' => $this->plainTextField(
					'embeddablecontent-field-softwareversion',
					(string)( $record['latestVersion'] ?? '' )
				),
			];

		foreach ( self::FOSS_ENTITY_FIELDS as $field ) {
			$harvested = (string)( $record[$field] ?? '' );
			$fields[$field] = [
				'type' => 'combobox',
				'options' => [],
				'label-message' => 'embeddablecontent-field-' . $field,
				'cssclass' => 'wb-entity-combobox',
				'help-message' => 'embeddablecontent-add-entityid-help',
			];
			if ( $harvested !== '' ) {
				// Plain text, HTML-escaped: the label comes from an external
				// API and must never inject markup.
				$fields[$field]['help'] = htmlspecialchars(
					$this->msg( 'embeddablecontent-software-field-harvested', $harvested )->text()
				);
			}
		}
		return $fields;
	}

	protected function createFromRecord( array $record, string $classItemId ): string {
		$record = $this->enrichRecord( $record );
		$specs = $this->softwareStatementSpecs( $record ) + $this->externalIdStatements( $record );
		return $this->createOrSkipItem( $this->primaryLabel( $record ), $classItemId, $specs, $record );
	}

	/**
	 * Manual-entry path: same software statement specs, no harvest (the
	 * form fields carry everything).
	 */
	protected function manualCreate( string $label, string $classItemId, array $record ): string {
		$specs = $this->softwareStatementSpecs( $record ) + $this->externalIdStatements( $record );
		return $this->createOrSkipItem( $label, $classItemId, $specs, $record );
	}

	/**
	 * FOSS statement specs from a (harvested or hand-entered) record:
	 * website/repository as validated URLs, version as string, and the
	 * item-typed facts as entity values referencing existing local items.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,\Wikibase\DataModel\DataValue> property id => DataValue
	 */
	protected function softwareStatementSpecs( array $record ): array {
		$sanitizer = new FragmentSanitizer();
		$specs = [];

		// URL facts — validated; invalid harvested URLs are dropped rather
		// than blocking creation (the author saw them on the review form).
		$website = $sanitizer->validateUrl( (string)( $record['website'] ?? '' ) );
		if ( $website !== null ) {
			$specs[$this->config->fossPropertyIds()['officialWebsite']] = new StringValue( $website );
		}
		$repository = $sanitizer->validateUrl( (string)( $record['sourceRepository'] ?? '' ) );
		if ( $repository !== null ) {
			$specs[$this->config->fossPropertyIds()['sourceRepository']] = new StringValue( $repository );
		}

		// Version string.
		$version = trim( (string)( $record['softwareVersion'] ?? '' ) );
		if ( $version !== '' ) {
			$specs[$this->config->fossPropertyIds()['softwareVersion']] = new StringValue( $version );
		}

		// Item-typed facts: entity combobox values (existing local items).
		foreach ( self::FOSS_ENTITY_FIELDS as $field ) {
			$itemId = $this->parseOptionalItemId( (string)( $record[$field] ?? '' ) );
			if ( $itemId === null ) {
				continue;
			}
			$propertyId = $field === 'programmingLanguage'
				// P5 doubles as the FOSS programming-language property.
				? $this->config->programmingLanguagePropertyId()
				: $this->config->fossPropertyIds()[$field];
			$specs[$propertyId] = new EntityIdValue( $itemId );
		}

		return $specs;
	}

	/**
	 * Creates the FOSS:<Name> wiki page (Template:FOSS skeleton) and
	 * sitelinks it to the just-created item, so the page renders the item's
	 * statements at view time. Idempotent: an existing page is left alone,
	 * the sitelink is (re)asserted.
	 *
	 * @return string|null redirect target URL, or null to keep the item redirect
	 */
	protected function afterCreate( string $itemId, array $record ): ?string {
		if ( !defined( 'NS_FOSS' ) ) {
			// Instance without the FOSS namespace: item-only flow.
			return null;
		}
		$label = $this->primaryLabel( $record );
		if ( trim( $label ) === '' ) {
			return null;
		}
		$title = Title::newFromText( 'FOSS:' . $label );
		if ( $title === null || !$title->inNamespace( NS_FOSS ) ) {
			// Invalid page title (e.g. contains #): keep the item only.
			return null;
		}

		// Sitelink the page ↔ item FIRST: the page's save-time parse must
		// find the link or its wikibase_item page property stays stale
		// ("unexpectedUnconnectedPage") and the infobox renders empty.
		// Page names are stored WITH SPACES (getItemIdForLink normalizes
		// underscores away) — getPrefixedDBkey() would be a silent mismatch.
		// Guard: on create-or-skip reuse the item may already carry the link
		// — never rewrite existing sitelink state.
		$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		if ( $item instanceof Item && !$item->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' ) ) {
			$item->getSiteLinkList()->setNewSiteLink( 'wikibase', $title->getPrefixedText() );
			WikibaseRepo::getStore()->newSiteLinkStore()->saveLinksOfItem( $item );
		}

		if ( !$title->exists() ) {
			$page = \MediaWiki\MediaWikiServices::getInstance()
				->getWikiPageFactory()->newFromTitle( $title );
			$content = new \MediaWiki\Content\WikitextContent( self::pageSkeleton() );
			$status = $page->doUserEditContent(
				$content,
				$this->getUser(),
				$this->msg( 'embeddablecontent-software-page-edit-summary', $label )->inContentLanguage()->text(),
				EDIT_NEW
			);
			if ( !$status->isOK() ) {
				// Page creation failed (e.g. protected namespace): the item
				// still exists — surface the item instead of erroring.
				return null;
			}
		}

		return $title->getFullURL();
	}

	/** Default FOSS: page skeleton — prose lives on the page, facts in the item. */
	private static function pageSkeleton(): string {
		return "{{FOSS}}\n\n== Overview ==\n\n<!-- What this software does and who it is for. -->\n\n"
			. "== Features ==\n\n== Alternatives ==\n\n== See also ==\n";
	}

	protected function classOptions(): array {
		return $this->config->fossClasses();
	}

	protected function defaultClassItemId( array $record ): ?string {
		$fossClasses = $this->config->fossClasses();
		return $fossClasses['foss'] ?? null;
	}

	private function parseOptionalItemId( string $input ): ?ItemId {
		$input = trim( $input );
		if ( $input === '' ) {
			return null;
		}
		try {
			$id = WikibaseRepo::getEntityIdParser()->parse( $input );
			return $id instanceof ItemId ? $id : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
