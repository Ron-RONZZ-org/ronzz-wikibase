<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Fetch\ProviderResult;
use Wikibase\DataModel\Entity\EntityIdValue;

/**
 * Special:AddPerson — create a person item from an external authority
 * (ORCID / VIAF / ISNI / Wikidata Q / name lookup), issue #7.
 *
 * Class is fixed to `person`; given/family names, authority IDs and the
 * birth/death facts are harvested where the provider returns them. The
 * review step adds date-of-birth/place-of-birth fields and a "deceased"
 * toggle revealing the date/place of death.
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddPerson extends SpecialAddExternalEntity {

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client,
		string $pageName = 'AddPerson'
	) {
		parent::__construct( $pageName, $config, $client );
	}

	protected function kindKey(): string {
		return 'person';
	}

	protected function buildSearchFields(): array {
		return [
			'name' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-name',
				'required' => false,
				'maxlength' => 250,
			],
			'orcid' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-orcid',
				'required' => false,
				'maxlength' => 19,
				'placeholder' => '0000-0000-0000-0000',
			],
			'viaf' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-viaf',
				'required' => false,
				'maxlength' => 32,
				'placeholder' => 'e.g. 29500134',
			],
			'isni' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-isni',
				'required' => false,
				'maxlength' => 32,
				'placeholder' => 'e.g. 0000 0001 2345 6789',
			],
		];
	}

	protected function search( array $data ): ProviderResult {
		$viaf = trim( (string)( $data['viaf'] ?? '' ) );
		if ( $viaf !== '' ) {
			return $this->client->byViaf( $viaf );
		}
		$isni = trim( (string)( $data['isni'] ?? '' ) );
		if ( $isni !== '' ) {
			return $this->client->byIsni( $isni );
		}
		$orcid = trim( (string)( $data['orcid'] ?? '' ) );
		if ( $orcid !== '' ) {
			return $this->client->byOrcid( $orcid );
		}
		$name = trim( (string)( $data['name'] ?? '' ) );
		if ( $name === '' ) {
			return new ProviderResult( [], [ 'No name, ORCID, VIAF or ISNI given' ] );
		}
		return $this->client->searchPersons( $name );
	}

	/**
	 * Manual-form autofill from the search inputs (issue #35): the `name`
	 * search box becomes given/family (all words except the last = given,
	 * last word = family); identifiers shared with the manual fields (orcid,
	 * isni, viaf) pass through via the base.
	 *
	 * @param array<string,mixed> $search
	 * @return array<string,mixed>
	 */
	protected function autofillRecord( array $search ): array {
		$out = parent::autofillRecord( $search );
		$name = trim( (string)( $search['name'] ?? '' ) );
		if ( $name !== '' ) {
			$out += NameSplitter::splitFullName( $name );
		}
		if ( !empty( $search['viaf'] ) ) {
			$out['viafId'] = (string)$search['viaf'];
		}
		return $out;
	}

	protected function candidateOptions( array $records ): array {
		return $this->candidateOptionLabels( $records );
	}

	protected function primaryLabel( array $record ): string {
		// The label is the FULL NAME, auto-generated from the given/family
		// names (issue #35) — an edited given/family set is always reflected
		// in the label; only a record WITHOUT name parts (a harvested
		// label-only candidate) keeps its harvested label.
		$given = trim( (string)( $record['givenName'] ?? '' ) );
		$family = trim( (string)( $record['familyName'] ?? '' ) );
		if ( $given !== '' || $family !== '' ) {
			return trim( $given . ' ' . $family );
		}
		return (string)( $record['label'] ?? '' );
	}

	/** @return array<string,string> authority identifiers relevant to persons */
	protected function externalIdRecordMap(): array {
		return [
			'wikidata' => 'wikidataId',
			'orcid' => 'orcid',
			'viaf' => 'viafId',
			'isni' => 'isni',
			'openalexAuthor' => 'openalexId',
		];
	}

	protected function harvest( string $qid ): ProviderResult {
		return $this->client->harvestPerson( $qid );
	}

	/**
	 * Persons harvest from ANY provider that resolved a Wikidata id (the
	 * dblp/OpenAlex candidates carry hub-derived Q-ids and are enriched from
	 * Wikidata) — unlike the other kinds, which only harvest hub records.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function canHarvest( array $record ): bool {
		return true;
	}

	/**
	 * Message key of the "I will upload a portrait image …" toggle.
	 * Overridden by Special:UpdatePerson with the "(replacing existing)"
	 * wording.
	 */
	protected function portraitIncludeMsgKey(): string {
		return 'embeddablecontent-person-portrait-include';
	}

	protected function reviewFieldSpecs( array $record ): array {
		$deceased = !empty( $record['dateOfDeath'] ) || !empty( $record['placeOfDeath'] );
		// NO editable label field (issue #35): the label is the full name,
		// auto-generated from given/family (primaryLabel).
		// Field order: the description sits BELOW given/family name (the
		// name identifies the person, the description qualifies them).
		return [
			'givenName' => $this->plainTextField( 'embeddablecontent-field-givenname', (string)( $record['givenName'] ?? '' ) ),
			'familyName' => $this->plainTextField( 'embeddablecontent-field-familyname', (string)( $record['familyName'] ?? '' ) ),
		]
		+ $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) )
		+ [
			'dateOfBirth' => [
				'type' => 'date',
				'label-message' => 'embeddablecontent-field-dateofbirth',
				'default' => (string)( $record['dateOfBirth'] ?? '' ),
			],
			'placeOfBirth' => $this->placeFieldSpec(
				'placeOfBirth',
				'embeddablecontent-field-placeofbirth',
				'embeddablecontent-field-placeofbirth-unresolved',
				(string)( $record['placeOfBirth'] ?? '' )
			),
			'deceased' => [
				'type' => 'check',
				'label-message' => 'embeddablecontent-field-deceased',
				'default' => $deceased,
			],
			'dateOfDeath' => [
				'type' => 'date',
				'label-message' => 'embeddablecontent-field-dateofdeath',
				'default' => (string)( $record['dateOfDeath'] ?? '' ),
				'hide-if' => [ '!==', 'deceased', '1' ],
			],
			'placeOfDeath' => $this->placeFieldSpec(
				'placeOfDeath',
				'embeddablecontent-field-placeofdeath',
				'embeddablecontent-field-placeofdeath-unresolved',
				(string)( $record['placeOfDeath'] ?? '' ),
				[ 'hide-if' => [ '!==', 'deceased', '1' ] ]
			),
			// Portrait (optional): collapsed behind the "I will upload a
			// portrait image for this person" toggle; local-file upload OR
			// pasted URL (validated via the shared uploadmeta button), the
			// file uploaded on create as File:<label>-portrait.<ext>. The
			// license is mandatory only when a portrait is actually provided
			// (enforced in beforeCreate, not by HTMLForm); author + license
			// info are free text. All field specs come from the shared
			// ImageUploadHelper (deduplicated with AddSoftware + Special:Upload).
			'portraitInclude' => \EmbeddableContent\Upload\ImageUploadHelper::includeField(
				'portrait', $this->portraitIncludeMsgKey()
			),
			'portraitMode' => \EmbeddableContent\Upload\ImageUploadHelper::modeField(
				'portrait',
				'embeddablecontent-person-portrait-mode',
				'embeddablecontent-person-portrait-mode-file',
				'embeddablecontent-person-portrait-mode-url',
				'embeddablecontent-upload-mode-existing'
			),
			'portraitFile' => \EmbeddableContent\Upload\ImageUploadHelper::fileField(
				'portrait', 'embeddablecontent-person-portrait-file'
			),
			'portraitUrl' => \EmbeddableContent\Upload\ImageUploadHelper::urlField(
				'portrait', 'embeddablecontent-person-portrait-url',
				$this->msg( 'embeddablecontent-person-portrait-license' )->text()
			),
			'portraitExisting' => \EmbeddableContent\Upload\ImageUploadHelper::existingField(
				'portrait', 'embeddablecontent-upload-existing'
			),
			'portraitLicense' => \EmbeddableContent\Upload\ImageUploadHelper::licenseField(
				'portrait',
				'embeddablecontent-person-portrait-license',
				'embeddablecontent-person-portrait-license-help',
				$this->config
			),
			'portraitAuthor' => \EmbeddableContent\Upload\ImageUploadHelper::authorField(
				'portrait', 'embeddablecontent-person-portrait-author'
			),
			'portraitLicenseInfo' => \EmbeddableContent\Upload\ImageUploadHelper::licenseInfoField(
				'portrait', 'embeddablecontent-person-portrait-license-info'
			),
		]
		+ $this->externalIdFieldSpecs( $record );
	}

	/**
	 * Entity combobox for place of birth / place of death.
	 *
	 * The harvested value arrives as a LABEL ("Cambridge") since the
	 * autofill-confirm update batch — the raw Wikidata QID was previously
	 * written blindly as a LOCAL item reference (a wrong/misleading
	 * statement). The label goes through the same entity-autofill flow as
	 * the harvested publisher/journal/license strings (resolveEntityField):
	 * a good local match PREFILLS the combobox with the local id AND
	 * renders a confirmation banner ("we think this corresponds to {label}
	 * (Q#)" — entityconfirm.js Yes/No); no match leaves the field empty
	 * with a "pick an existing item, or create it first" hint. An
	 * already-local id (Special:UpdatePerson's recordFromItem) passes
	 * through unchanged.
	 *
	 * @param string $fieldKey the form field key (wp + key = input name)
	 * @param string $labelMessage message key of the field label
	 * @param string $unresolvedMessage message key with $1 = the place label
	 * @param string $harvested the record's place value (label or local QID)
	 * @param array<string,mixed> $extra extra field spec keys (e.g. hide-if)
	 * @return array<string,mixed>
	 */
	private function placeFieldSpec( string $fieldKey, string $labelMessage, string $unresolvedMessage, string $harvested, array $extra = [] ): array {
		$default = '';
		$help = '';
		if ( $harvested !== '' && preg_match( '/^Q[1-9]\d*$/i', $harvested ) !== 1 ) {
			// A harvested label: resolve to an existing local item.
			$resolved = $this->resolveEntityField( $harvested );
			if ( $resolved !== null ) {
				$default = $resolved['id'];
				$help = $this->entityConfirmHtml(
					'wp' . $fieldKey,
					$this->msg( $labelMessage )->text(),
					$harvested,
					$resolved['label'],
					$resolved['id']
				);
			} else {
				// Plain text, HTML-escaped: the value comes from an external
				// API and must never inject markup.
				$help = htmlspecialchars(
					$this->msg( $unresolvedMessage, $harvested )->text()
				);
			}
		} elseif ( $harvested !== '' ) {
			// Already a local item id (the Update flow).
			$default = $harvested;
		}
		$spec = [
			'type' => 'combobox',
			'options' => [],
			'label-message' => $labelMessage,
			'cssclass' => 'wb-entity-combobox',
			'default' => $default,
		];
		if ( $help !== '' ) {
			$spec['help'] = $help;
		}
		return array_merge( $spec, $extra );
	}

	/**
	 * Person statement specs: the base authority/citation facts plus the
	 * birth/death facts — dates as day-precision TimeValues, places as
	 * entity values referencing existing local items — plus the portrait
	 * (uploaded File: URL on the `image` property) and its license.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,\Wikibase\DataModel\DataValue|\Wikibase\DataModel\DataValue[]>
	 */
	protected function statementSpecs( array $record ): array {
		$specs = parent::statementSpecs( $record );
		$props = $this->config->personPropertyIds();

		foreach ( [ 'dateOfBirth', 'dateOfDeath' ] as $field ) {
			if ( !isset( $props[$field] ) || empty( $record[$field] ) ) {
				continue;
			}
			$time = $this->dateToTimeValue( (string)$record[$field] );
			if ( $time !== null ) {
				$specs[$props[$field]] = $time;
			}
		}
		foreach ( [ 'placeOfBirth', 'placeOfDeath' ] as $field ) {
			if ( !isset( $props[$field] ) || empty( $record[$field] ) ) {
				continue;
			}
			$itemId = $this->parseItemId( (string)$record[$field] );
			if ( $itemId !== null ) {
				$specs[$props[$field]] = new EntityIdValue( $itemId );
			}
		}
		// Portrait: the uploaded File:<label>-portrait.<ext> URL (image
		// statement, P18-aligned) + the image license entity (P275-aligned)
		// + the free-text attribution strings (P2093-aligned image author +
		// unaligned additional license information).
		if ( !empty( $record['portraitFileTitle'] ) && isset( $props['image'] ) ) {
			$fileTitle = \MediaWiki\Title\Title::makeTitle( NS_FILE, (string)$record['portraitFileTitle'] );
			if ( $fileTitle !== null ) {
				$specs[$props['image']] = new \DataValues\StringValue( $fileTitle->getFullURL() );
			}
		}
		if ( !empty( $record['portraitLicense'] ) && isset( $props['license'] ) ) {
			$licenseItem = $this->parseItemId( (string)$record['portraitLicense'] );
			if ( $licenseItem !== null ) {
				$specs[$props['license']] = new EntityIdValue( $licenseItem );
			}
		}
		foreach ( [ 'imageAuthor' => 'portraitAuthor', 'imageLicenseInfo' => 'portraitLicenseInfo' ] as $propKey => $field ) {
			if ( !empty( $record[$field] ) && isset( $props[$propKey] ) ) {
				$specs[$props[$propKey]] = new \DataValues\StringValue( (string)$record[$field] );
			}
		}
		return $specs;
	}

	// ------------------------------------------------------------- portrait
	// The portrait upload machinery (field specs, file/URL upload,
	// dest naming, verify+performUpload, page text) lives once in
	// ImageUploadHelper — AddSoftware's logo and Special:Upload use it too.

	/**
	 * Uploads the optional portrait (local file or pasted URL, per the
	 * portraitMode toggle, behind the portraitInclude toggle) as
	 * File:<label>-portrait.<ext> and records the file title in
	 * $record['portraitFileTitle'] for the image statement. When a portrait
	 * IS provided, its license is mandatory. Idempotent: an already-uploaded
	 * file is left alone. A provided portrait that cannot be honoured aborts
	 * the creation (never silent). Delegates to the shared ImageUploadHelper
	 * (the same machinery AddSoftware's logo and Special:Upload use).
	 *
	 * @param array<string,mixed> $record
	 * @return string|null error message, or null to proceed
	 */
	protected function beforeCreate( array &$record ): ?string {
		return \EmbeddableContent\Upload\ImageUploadHelper::handleUpload(
			'portrait',
			$record,
			$this->getContext(),
			$this->getUser(),
			[
				'error' => 'embeddablecontent-person-portrait-error',
				'licenseRequired' => 'embeddablecontent-person-portrait-license-required',
				'editSummary' => 'embeddablecontent-person-portrait-edit-summary',
			],
			fn ( array $record ) => $this->primaryLabel( $record )
		);
	}

	// ------------------------------------------------------------- page content
	// Person: page content = the Wikipedia lead intro (Biography section),
	// reviewed on the content step before it is written to the page.

	/** @var \EmbeddableContent\Fetch\WikipediaContentProvider|null lazily built */
	private ?\EmbeddableContent\Fetch\WikipediaContentProvider $wikipedia = null;

	private function wikipediaContent(): \EmbeddableContent\Fetch\WikipediaContentProvider {
		$this->wikipedia ??= \MediaWiki\MediaWikiServices::getInstance()
			->get( 'EmbeddableContent.WikipediaContent' );
		return $this->wikipedia;
	}

	protected function harvestContent( array $record ): array {
		$title = trim( (string)( $record['enwikiTitle'] ?? '' ) );
		if ( $title === '' ) {
			return $record;
		}
		$intro = $this->wikipediaContent()->intro( $title );
		if ( $intro !== null ) {
			$record['biography'] = $intro;
			$record['contentSources']['biography'] = 'wikipedia';
		}
		return $record;
	}

	protected function contentFieldSpecs( array $record ): array {
		$bio = (string)( $record['biography'] ?? '' );
		if ( $bio === '' ) {
			return [];
		}
		$field = [
			'type' => 'textarea',
			'rows' => 8,
			'label-message' => 'embeddablecontent-content-field-biography',
			'default' => $bio,
		];
		$source = $record['contentSources']['biography'] ?? null;
		if ( $source !== null ) {
			$field['help'] = $this->msg( 'embeddablecontent-content-from-' . $source )->parse();
		}
		return [ 'biography' => $field ];
	}

	// ------------------------------------------------------------- classic page
	// The base afterCreate() writes a sitelinked Person:<label> page (the
	// issue-#26 AddSoftware pattern); this class declares the page facts.

	protected function pageNamespace(): ?int {
		return defined( 'NS_PERSON' ) ? NS_PERSON : null;
	}

	protected function pageTemplate(): string {
		return 'Person';
	}

	/**
	 * Person: page skeleton — prose lives on the page, facts in the item.
	 * The portrait (when uploaded) is passed to Template:Person, which
	 * renders it inside the infobox (the AddSoftware/FOSS pattern). Only
	 * sections with (reviewed) content are rendered: the Wikipedia
	 * Biography when fetched; when none is available, the item's description
	 * is the == Overview == placeholder. Never an empty scaffold.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageSkeleton( array $record, bool $withMarker = false ): string {
		$marker = $withMarker ? "\n<!-- " . $this->pagePendingMarker() . " -->\n" : "";
		$portraitFile = (string)( $record['portraitFileTitle'] ?? '' );
		$portraitParam = $portraitFile !== ''
			? '|portrait=[[File:' . $portraitFile . '|frameless|220px|Portrait]]'
			: '';
		$body = "{{Person{$portraitParam}}}\n\n";
		$bio = trim( (string)( $record['biography'] ?? '' ) );
		if ( $bio !== '' ) {
			$body .= "== Biography ==\n\n" . $this->attributed( $record, 'biography', $bio ) . "\n\n";
		} else {
			$overview = trim( (string)( $record['description'] ?? '' ) );
			if ( $overview !== '' ) {
				$body .= "== Overview ==\n\n{$overview}\n\n";
			}
		}
		return $body . $marker;
	}

	protected function classOptions(): array {
		$classes = $this->config->agentClasses();
		$options = [];
		foreach ( $classes as $key => $id ) {
			if ( $key === 'person' ) {
				$options['person'] = $id;
			}
		}
		return $options;
	}

	protected function defaultClassItemId( array $record ): ?string {
		return $this->config->agentClasses()['person'] ?? null;
	}
}
