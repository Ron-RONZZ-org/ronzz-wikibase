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


	/** The create path delegates to the shared semantic flow service. */
	protected function semanticFlowKindKey(): ?string {
		return 'person';
	}

	/**
	 * The form record → the shared service vocabulary: the OSM place
	 * external-ids and the portrait file URL (the API contract's item-typed
	 * place fields are not written by the forms — places live in OSM).
	 */
	protected function semanticFlowRecord( string $kind, array $record ): array {
		$out = $this->pickServiceFields( $record, \EmbeddableContent\Flow\SemanticEntityFieldMap::fieldsForKind( 'person' ) );
		// The form's website field is keyed 'website'; the service contract
		// calls it officialWebsite.
		if ( !empty( $record['website'] ) ) {
			$out['officialWebsite'] = (string)$record['website'];
		}

		// The forms write places as OSM external-ids only — drop the
		// item-typed place fields (a harvested Wikidata QID is not a local
		// item and must never become a statement).
		unset( $out['placeOfBirth'], $out['placeOfDeath'] );
		foreach ( [ 'placeOfBirthOsm', 'placeOfDeathOsm' ] as $osmField ) {
			if ( !empty( $record[$osmField] ) ) {
				$out[$osmField] = (string)$record[$osmField];
			}
		}
		if ( !empty( $record['portraitFileTitle'] ) ) {
			$title = \MediaWiki\Title\Title::makeTitle( NS_FILE, (string)$record['portraitFileTitle'] );
			if ( $title !== null ) {
				$out['imageFileUrl'] = $title->getFullURL();
			}
		}
		return $out;
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
			'placeOfBirthOsm' => $this->osmPlaceFieldSpec(
				'placeOfBirthOsm',
				'embeddablecontent-field-placeofbirth',
				'embeddablecontent-field-placeofbirth-osm-hint',
				$record
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
			'placeOfDeathOsm' => $this->osmPlaceFieldSpec(
				'placeOfDeathOsm',
				'embeddablecontent-field-placeofdeath',
				'embeddablecontent-field-placeofdeath-osm-hint',
				$record,
				[ 'hide-if' => [ '!==', 'deceased', '1' ] ]
			),
		]
		// Official website (optional URL field, shared with AddSoftware/
		// AddCollective — the P856-aligned property).
		+ $this->websiteFieldSpec( $record )
		+ [
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
	 * OSM search combobox for place of birth / place of death.
	 *
	 * The harvested value arrives as a LABEL ("Cambridge") since the
	 * autofill-confirm update batch — the raw Wikidata QID was previously
	 * written blindly as a LOCAL item reference (a wrong/misleading
	 * statement). Three prefill states:
	 *  1. an auto-matched OSM id (harvestContent's Nominatim lookup) →
	 *     prefilled with the node|way|relation/<id> value AND the
	 *     fetch-match-confirm banner (the portrait-license pattern:
	 *     "we think this corresponds to {display name} (node/123)"
	 *     [Yes, that's right] / [No, let me correct]);
	 *  2. a stored OSM id (Special:UpdatePerson's recordFromItem) → plain
	 *     prefill, no banner;
	 *  3. a harvested label with NO match → empty field + the "search
	 *     OpenStreetMap to confirm" hint (a raw name would fail the
	 *     server-side id validation on submit, so it never prefills).
	 *
	 * The combobox carries the `wb-osm-combobox` cssclass; resources/
	 * osmsuggest.js wires it to the Nominatim search API (browser-first).
	 *
	 * @param string $fieldKey the form field key (wp + key = input name)
	 * @param string $labelMessage message key of the field label
	 * @param string $hintMessage message key with $1 = the harvested place label
	 * @param array<string,mixed> $record the review record (label, matched
	 *  OSM id, confirm payload)
	 * @param array<string,mixed> $extra extra field spec keys (e.g. hide-if)
	 * @return array<string,mixed>
	 */
	private function osmPlaceFieldSpec( string $fieldKey, string $labelMessage, string $hintMessage, array $record, array $extra = [] ): array {
		$labelKey = $fieldKey === 'placeOfBirthOsm' ? 'placeOfBirth' : 'placeOfDeath';
		$harvested = (string)( $record[$labelKey] ?? '' );
		$matched = (string)( $record[$fieldKey] ?? '' );
		$confirm = $record[$fieldKey . 'Confirm'] ?? null;

		$default = '';
		$help = '';
		if ( $matched !== '' && \EmbeddableContent\Spec\OsmPlace::isValidId( $matched ) ) {
			$default = $matched;
		}
		if ( is_array( $confirm ) ) {
			// Fetch-match-confirm (the portrait-license pattern): the
			// harvested label was auto-matched to an OSM entity — the user
			// confirms or corrects before submit.
			$help = $this->entityConfirmHtml(
				'wp' . $fieldKey,
				$this->msg( $labelMessage )->text(),
				(string)( $confirm['fetched'] ?? '' ),
				(string)( $confirm['label'] ?? '' ),
				(string)( $confirm['id'] ?? '' )
			);
		} elseif ( $harvested !== '' && $default === '' ) {
			// A harvested label with no auto-match: the OSM search hint.
			// Plain text, HTML-escaped: the value comes from an external
			// API and must never inject markup.
			$help = htmlspecialchars(
				$this->msg( $hintMessage, $harvested )->text()
			);
		}
		$spec = [
			'type' => 'combobox',
			'options' => [],
			'label-message' => $labelMessage,
			'cssclass' => 'wb-osm-combobox',
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
	 * EXTERNAL-ID OSM values (node/way/relation ids picked from the
	 * Nominatim combobox) — plus the portrait (uploaded File: URL on the
	 * `image` property) and its license.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,\Wikibase\DataModel\DataValue|\Wikibase\DataModel\DataValue[]>
	 */

	// ------------------------------------------------------------- portrait
	// The portrait upload machinery (field specs, file/URL upload,
	// dest naming, verify+performUpload, page text) lives once in
	// ImageUploadHelper — AddSoftware's logo and Special:Upload use it too.

	/**
	 * Uploads the optional portrait (local file or pasted URL, per the
	 * portraitMode toggle, behind the portraitInclude toggle) as
	 * File:<label>-portrait.<ext> and records the file title in
	 * $record['portraitFileTitle'] for the image statement. When a NEW
	 * portrait IS provided, its license is mandatory (recorded on the file's
	 * own image item + File: page); reusing an existing file needs no
	 * license. Idempotent: an already-uploaded file is left alone. A
	 * provided portrait that cannot be honoured aborts the creation (never
	 * silent). Delegates to the shared ImageUploadHelper (the same machinery
	 * AddSoftware's logo and Special:Upload use).
	 *
	 * @param array<string,mixed> $record
	 * @return string|null error message, or null to proceed
	 */
	protected function beforeCreate( array &$record ): ?string {
		// OSM places: a filled-in field must carry a Nominatim-picked
		// node/way/relation id — a raw place name (e.g. an unpicked
		// harvested label) is a form error, never a silent drop.
		foreach ( [ 'placeOfBirthOsm', 'placeOfDeathOsm' ] as $field ) {
			$value = trim( (string)( $record[$field] ?? '' ) );
			if ( $value !== '' && !\EmbeddableContent\Spec\OsmPlace::isValidId( $value ) ) {
				return $this->msg( 'embeddablecontent-field-place-osm-error' )->text();
			}
		}
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
			fn ( array $record ) => $this->primaryLabel( $record ),
			$this->config
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

	/** @var \EmbeddableContent\Fetch\NominatimProvider|null lazily built */
	private ?\EmbeddableContent\Fetch\NominatimProvider $nominatim = null;

	private function nominatim(): \EmbeddableContent\Fetch\NominatimProvider {
		$this->nominatim ??= \MediaWiki\MediaWikiServices::getInstance()
			->get( 'EmbeddableContent.Nominatim' );
		return $this->nominatim;
	}

	/**
	 * Harvest-on-pick enrichment: the Wikipedia lead intro + the OSM place
	 * auto-match — a harvested place LABEL is searched on Nominatim
	 * (server-side, rate-limited); a top match prefills the OSM field with
	 * the node/way/relation id AND a fetch-match-confirm banner (the
	 * portrait-license pattern) so the user confirms or corrects. No match
	 * / unreachable Nominatim → the plain "search OpenStreetMap to confirm"
	 * hint on the review form. Best-effort: never throws.
	 */
	protected function harvestContent( array $record ): array {
		$title = trim( (string)( $record['enwikiTitle'] ?? '' ) );
		if ( $title !== '' ) {
			$intro = $this->wikipediaContent()->intro( $title );
			if ( $intro !== null ) {
				$record['biography'] = $intro;
				$record['contentSources']['biography'] = 'wikipedia';
			}
		}
		foreach ( [ 'placeOfBirth' => 'placeOfBirthOsm', 'placeOfDeath' => 'placeOfDeathOsm' ] as $labelKey => $osmKey ) {
			$label = trim( (string)( $record[$labelKey] ?? '' ) );
			if ( $label === '' || !empty( $record[$osmKey] ) || !empty( $record[$osmKey . 'Confirm'] ) ) {
				continue;
			}
			try {
				$match = $this->nominatim()->topMatchForLabel( $label, $this->getLanguage()->getCode() );
			} catch ( \Throwable $e ) {
				$match = null; // Nominatim down → the plain search hint.
			}
			if ( $match !== null ) {
				$record[$osmKey] = $match['osmType'] . '/' . $match['osmId'];
				$record[$osmKey . 'Confirm'] = [
					'fetched' => $label,
					'label' => $match['displayName'],
					'id' => $record[$osmKey],
				];
			}
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
