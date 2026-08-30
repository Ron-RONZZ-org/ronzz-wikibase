<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Fetch\ProviderResult;

/**
 * Special:AddCollective — create a non-person agent item (organization,
 * company, band, collective, institution) from Wikidata, issue #7.
 *
 * Class is inferred from the harvested instance-of hints (mapped through the
 * config), with a manual class picker fallback.
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddCollective extends SpecialAddExternalEntity {

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client,
		string $pageName = 'AddCollective'
	) {
		parent::__construct( $pageName, $config, $client );
	}

	protected function kindKey(): string {
		return 'collective';
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
			return new ProviderResult( [], [ 'No name given' ] );
		}
		return $this->client->searchEntities( $name );
	}

	/**
	 * Manual-form autofill (issue #35): the search `name` box becomes the
	 * manual `label` field.
	 *
	 * @param array<string,mixed> $search
	 * @return array<string,mixed>
	 */
	protected function autofillRecord( array $search ): array {
		$name = trim( (string)( $search['name'] ?? '' ) );
		return $name === '' ? parent::autofillRecord( $search ) : [ 'label' => $name ];
	}

	protected function candidateOptions( array $records ): array {
		return $this->candidateOptionLabels( $records );
	}

	protected function primaryLabel( array $record ): string {
		return (string)( $record['label'] ?? '' );
	}

	/** @return array<string,string> authority identifiers relevant to collectives */
	protected function externalIdRecordMap(): array {
		return [ 'wikidata' => 'wikidataId' ];
	}

	protected function harvest( string $qid ): ProviderResult {
		return $this->client->harvestEntity( $qid );
	}

	/**
	 * Message key of the "I will upload a logo image …" toggle. Overridden
	 * by Special:UpdateCollective with the "(replacing existing)" wording.
	 */
	protected function logoIncludeMsgKey(): string {
		return 'embeddablecontent-collective-logo-include';
	}

	protected function reviewFieldSpecs( array $record ): array {
		$fields = $this->labelFieldSpec( 'label', 'embeddablecontent-add-label', (string)( $record['label'] ?? '' ) )
			+ $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) )
			+ [
				// Optional parent organization (issue follow-up): an entity
				// combobox over existing items, writing the P749-aligned
				// statement. Filled but invalid ids are skipped (the same
				// lenient contract as the AddPerson place fields).
				'parentOrganization' => [
					'type' => 'combobox',
					'options' => [],
					'label-message' => 'embeddablecontent-field-parentorganization',
					'cssclass' => 'wb-entity-combobox',
					'default' => (string)( $record['parentOrganization'] ?? '' ),
					'help' => $this->msg( 'embeddablecontent-field-parentorganization-help' )->parse(),
				],
			]
			// Official website (optional URL field, shared with AddSoftware/
			// AddPerson — the P856-aligned property).
			+ $this->websiteFieldSpec( $record );
		// Logo (optional): collapsed behind the "I will upload a logo
		// image for this collective" toggle; the shared ImageUploadHelper
		// owns the field specs + upload path (same machinery as
		// AddSoftware's logo, AddPerson's portrait and Special:Upload).
		$fields['logoInclude'] = \EmbeddableContent\Upload\ImageUploadHelper::includeField(
			'logo', $this->logoIncludeMsgKey()
		);
		$fields['logoMode'] = \EmbeddableContent\Upload\ImageUploadHelper::modeField(
			'logo',
			'embeddablecontent-collective-logo-mode',
			'embeddablecontent-software-logo-mode-file',
			'embeddablecontent-software-logo-mode-url',
			'embeddablecontent-upload-mode-existing'
		);
		$fields['logoFile'] = \EmbeddableContent\Upload\ImageUploadHelper::fileField(
			'logo', 'embeddablecontent-collective-logo-file'
		);
		$fields['logoUrl'] = \EmbeddableContent\Upload\ImageUploadHelper::urlField(
			'logo', 'embeddablecontent-collective-logo-url',
			$this->msg( 'embeddablecontent-collective-logo-license' )->text()
		);
		$fields['logoExisting'] = \EmbeddableContent\Upload\ImageUploadHelper::existingField(
			'logo', 'embeddablecontent-upload-existing'
		);
		$fields['logoLicense'] = \EmbeddableContent\Upload\ImageUploadHelper::licenseField(
			'logo',
			'embeddablecontent-collective-logo-license',
			'embeddablecontent-collective-logo-license-help',
			$this->config
		);
		$fields['logoAuthor'] = \EmbeddableContent\Upload\ImageUploadHelper::authorField(
			'logo', 'embeddablecontent-collective-logo-author'
		);
		$fields['logoLicenseInfo'] = \EmbeddableContent\Upload\ImageUploadHelper::licenseInfoField(
			'logo', 'embeddablecontent-collective-logo-license-info'
		);
		return $fields + $this->externalIdFieldSpecs( $record );
	}

	// ------------------------------------------------------------- logo
	// The logo field specs + upload machinery live once in ImageUploadHelper
	// (AddSoftware's logo, AddPerson's portrait and Special:Upload share it).

	/**
	 * Uploads the optional logo (local file or pasted URL, per the logoMode
	 * toggle, behind the logoInclude toggle) as File:<label>-logo.<ext> and
	 * records the file title in $record['logoFileTitle'] for the image
	 * statement. The logo license is mandatory when a NEW logo is uploaded
	 * (it is recorded on the file's own image item + file page — see
	 * ImageUploadHelper); reusing an existing file needs no license. A
	 * provided logo that cannot be honoured aborts the creation (never
	 * silent). Delegates to the shared ImageUploadHelper.
	 *
	 * @param array<string,mixed> $record
	 * @return string|null error message, or null to proceed
	 */
	protected function beforeCreate( array &$record ): ?string {
		return \EmbeddableContent\Upload\ImageUploadHelper::handleUpload(
			'logo',
			$record,
			$this->getContext(),
			$this->getUser(),
			[
				'error' => 'embeddablecontent-collective-logo-error',
				'licenseRequired' => 'embeddablecontent-collective-logo-license-required',
				'editSummary' => 'embeddablecontent-collective-logo-edit-summary',
			],
			fn ( array $record ) => $this->primaryLabel( $record ),
			$this->config
		);
	}


	/**
	 * Collective statements: the base authority/citation facts plus the
	 * optional parent organization entity link and the logo — ONLY the
	 * `image` statement references the file; the license/author/license-info
	 * live on the file's own image item + File: page (semantic-first model,
	 * ImageUploadHelper), never on the consumer entity.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,\Wikibase\DataModel\DataValue|\Wikibase\DataModel\DataValue[]>
	 */
	protected function statementSpecs( array $record ): array {
		$specs = parent::statementSpecs( $record );
		$props = $this->config->collectivePropertyIds();
		$parent = trim( (string)( $record['parentOrganization'] ?? '' ) );
		if ( $parent !== '' && isset( $props['parentOrganization'] ) ) {
			$itemId = $this->parseItemId( $parent );
			if ( $itemId !== null ) {
				$specs[$props['parentOrganization']] = new \Wikibase\DataModel\Entity\EntityIdValue( $itemId );
			}
		}
		// Official website: optional validated URL statement (the shared
		// P856-aligned property).
		$website = $this->websiteStatementValue( $record );
		if ( $website !== null && isset( $props['officialWebsite'] ) ) {
			$specs[$props['officialWebsite']] = $website;
		}
		if ( !empty( $record['logoFileTitle'] ) && isset( $props['image'] ) ) {
			$fileTitle = \MediaWiki\Title\Title::makeTitle( NS_FILE, (string)$record['logoFileTitle'] );
			if ( $fileTitle !== null ) {
				$specs[$props['image']] = new \DataValues\StringValue( $fileTitle->getFullURL() );
			}
		}
		return $specs;
	}

	// ------------------------------------------------------------- classic page
	// The base afterCreate() writes a sitelinked Collective:<label> page
	// (the issue-#26 AddSoftware pattern); this class declares the page facts.

	protected function pageNamespace(): ?int {
		return defined( 'NS_COLLECTIVE' ) ? NS_COLLECTIVE : null;
	}

	protected function pageTemplate(): string {
		return 'Collective';
	}

	/**
	 * Collective: page skeleton — the template + the item description as an
	 * == Overview == placeholder when one is available (collectives currently
	 * fetch no page content, so the description is the lead; the contributor
	 * adds sections by editing). The logo (when uploaded) is passed to
	 * Template:Collective, which renders it inside the infobox (the
	 * AddSoftware/FOSS pattern).
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageSkeleton( array $record, bool $withMarker = false ): string {
		$marker = $withMarker ? "\n<!-- " . $this->pagePendingMarker() . " -->\n" : "";
		$logoFile = (string)( $record['logoFileTitle'] ?? '' );
		$logoParam = $logoFile !== ''
			? '|logo=[[File:' . $logoFile . '|frameless|220px|Logo]]'
			: '';
		$body = "{{Collective{$logoParam}}}\n\n";
		$overview = trim( (string)( $record['description'] ?? '' ) );
		if ( $overview !== '' ) {
			$body .= "== Overview ==\n\n{$overview}\n\n";
		}
		return $body . $marker;
	}

	protected function classOptions(): array {
		$options = [];
		foreach ( $this->config->agentClasses() as $key => $id ) {
			if ( $key !== 'person' ) {
				$options[$key] = $id;
			}
		}
		return $options;
	}

	protected function defaultClassItemId( array $record ): ?string {
		foreach ( $record['classWikidataIds'] ?? [] as $qid ) {
			$key = $this->config->agentClassByWikidata()[$qid] ?? null;
			if ( $key !== null && isset( $this->config->agentClasses()[$key] ) ) {
				return $this->config->agentClasses()[$key];
			}
		}
		return $this->config->agentClasses()['organization'] ?? null;
	}
}
