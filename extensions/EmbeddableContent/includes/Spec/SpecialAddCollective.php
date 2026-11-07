<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Fetch\ProviderResult;
use EmbeddableContent\Fields\EntityCombobox;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

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


	/** The create path delegates to the shared semantic flow service. */
	protected function semanticFlowKindKey(): ?string {
		return 'collective';
	}

	/**
	 * The class chosen on the review/manual form rides the record as
	 * `collectiveClass` (the shared service's field name) — the form's own
	 * field is the generic `class` select, so without this mapping the
	 * service fell back to `organization` and every collective created
	 * through the browser form was misclassified (the Q2019 report).
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,mixed>
	 */
	protected function withChosenClass( array $record, string $classItemId ): array {
		if ( $classItemId !== '' ) {
			$record['collectiveClass'] = $classItemId;
		}
		return $record;
	}

	/**
	 * The form record → the shared service vocabulary (the logo file URL).
	 */
	protected function semanticFlowRecord( string $kind, array $record ): array {
		$out = $this->pickServiceFields( $record, \EmbeddableContent\Flow\SemanticEntityFieldMap::fieldsForKind( 'collective' ) );
		// Empty members is MEANINGFUL on update (remove every has-member
		// statement); pickServiceFields drops empty values, so carry the
		// key explicitly.
		if ( array_key_exists( 'members', $record ) ) {
			$out['members'] = (string)$record['members'];
		}
		// The form's website field is keyed 'website'; the service contract
		// calls it officialWebsite.
		if ( !empty( $record['website'] ) ) {
			$out['officialWebsite'] = (string)$record['website'];
		}

		if ( !empty( $record['logoFileTitle'] ) ) {
			$title = \MediaWiki\Title\Title::makeTitle( NS_FILE, (string)$record['logoFileTitle'] );
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
		$label = (string)( $record['label'] ?? '' );
		if ( $label !== '' ) {
			return $label;
		}
		// Group of humans with a pending create-member decision: the label
		// is auto-generated from the created Persons at commit time. The
		// create gate only needs a non-empty provisional label; the names
		// panel supplies it until the Persons exist.
		$groupClass = $this->config->agentClasses()['groupOfHumans'] ?? null;
		$class = (string)( $record['collectiveClass'] ?? $this->getRequest()->getVal( 'wpclass', '' ) );
		if ( $groupClass !== null && $class === $groupClass ) {
			$parsed = AuthorNameList::parse( (string)( $record['memberNames'] ?? '' ) );
			$names = array_map(
				static fn ( array $name ): string => trim( $name['givenName'] . ' ' . $name['familyName'] ),
				$parsed['names']
			);
			return GroupLabel::fromMembers( $names );
		}
		return '';
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
		$fields = $this->labelFieldSpec( 'label', 'embeddablecontent-add-label', (string)( $record['label'] ?? '' ) );
		// A Group of humans may leave the label blank: it is auto-generated
		// from the members. Other collective classes still require it (the
		// submit validation enforces the difference).
		$fields['label']['required'] = false;
		$fields['label']['help-message'] = 'embeddablecontent-collective-label-help';
		$fields += $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) );
		$fields += $this->labelLanguageFieldSpec( $record );
		// Optional parent organization (issue follow-up): a class-scoped
		// entity combobox over agent items, writing the P749-aligned
		// statement. Filled but invalid ids are skipped (the same lenient
		// contract as the AddPerson place fields).
		$fields['parentOrganization'] = EntityCombobox::spec(
			'embeddablecontent-field-parentorganization',
			$this->agentClassIds(),
			false,
			[
				'default' => (string)( $record['parentOrganization'] ?? '' ),
				'help' => $this->msg( 'embeddablecontent-field-parentorganization-help' )->parse(),
			]
		);
		$groupClass = $this->config->agentClasses()['groupOfHumans'] ?? null;
		if ( $groupClass !== null ) {
			$hide = [ '!==', 'class', $groupClass ];
			$personClass = $this->config->agentClasses()['person'] ?? null;
			$fields['members'] = EntityCombobox::spec(
				'embeddablecontent-collective-members',
				$personClass !== null ? [ $personClass ] : [],
				true,
				[
					'default' => (string)( $record['members'] ?? '' ),
					'help-message' => 'embeddablecontent-collective-members-help',
					'hide-if' => $hide,
				]
			);
			$fields['memberNames'] = [
				'type' => 'textarea',
				'rows' => 3,
				'label-message' => 'embeddablecontent-collective-membernames',
				'help-message' => 'embeddablecontent-collective-membernames-help',
				'default' => (string)( $record['memberNames'] ?? '' ),
				'hide-if' => $hide,
			];
			$fields['memberSearch'] = [
				'type' => 'info',
				'raw' => true,
				'default' => '<button type="button" id="wb-member-search" class="mw-ui-button mw-ui-progressive">'
					. htmlspecialchars( $this->msg( 'embeddablecontent-collective-membersearch' )->text() )
					. '</button><div id="wb-member-results" class="wb-author-results"></div>',
				'hide-if' => $hide,
			];
			$fields['memberChoices'] = [
				'type' => 'hidden',
				'default' => (string)( $record['memberChoices'] ?? '' ),
				'hide-if' => $hide,
			];
		}
		// Official website (optional URL field, shared with AddSoftware/
		// AddPerson — the P856-aligned property).
		$fields += $this->websiteFieldSpec( $record );
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
		$fields['logoClipboard'] = \EmbeddableContent\Upload\ImageUploadHelper::clipboardField(
			'logo',
			$this->msg( 'embeddablecontent-upload-clipboard-hint' )->escaped()
		);
		foreach ( \EmbeddableContent\Upload\ImageUploadHelper::imageToolsFields( 'logo' ) as $key => $spec ) {
			$fields[$key] = $spec;
		}
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
		foreach ( \EmbeddableContent\Upload\ImageUploadHelper::authorFields(
			'logo', 'embeddablecontent-collective-logo-author', $this->config
		) as $key => $spec ) {
			$fields[$key] = $spec;
		}
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
		$memberError = $this->beforeCreateMembers( $record );
		if ( $memberError !== null ) {
			return $memberError;
		}
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

	// ------------------------------------------------------------- classic page
	// The base afterCreate() writes a sitelinked Collective:<label> page
	// (the issue-#26 AddSoftware pattern); this class declares the page facts.

	/**
	 * Group-of-humans members: read-only pre-flight (the Persons are
	 * created in commitRecord after the duplicate guard). A blank label is
	 * auto-generated from the members; create decisions may defer the label
	 * to the commit step.
	 *
	 * @param array<string,mixed> $record
	 */
	private function beforeCreateMembers( array &$record ): ?string {
		$groupClass = $this->config->agentClasses()['groupOfHumans'] ?? null;
		$class = (string)( $record['collectiveClass'] ?? $this->getRequest()->getVal( 'wpclass', '' ) );
		if ( $groupClass !== null && $class === $groupClass ) {
			$error = $this->applyPersonPanel( 'member', $record, false );
			if ( $error !== null ) {
				return $error;
			}
			if ( trim( (string)( $record['label'] ?? '' ) ) === '' ) {
				$memberIds = ItemIdList::split( (string)( $record['members'] ?? '' ) );
				if ( $memberIds !== [] ) {
					$record['label'] = $this->groupLabelFor( $memberIds );
				} elseif ( !$this->hasCreateMemberDecisions( (string)( $record['memberChoices'] ?? '' ) ) ) {
					return $this->msg( 'embeddablecontent-add-error-required' )->text();
				}
			}
		} else {
			// Members are a group-of-humans concept. Clear them on another
			// class so a class change also removes stale has-member claims.
			$record['members'] = '';
			$record['memberNames'] = '';
			$record['memberChoices'] = '';
			if ( trim( (string)( $record['label'] ?? '' ) ) === '' ) {
				return $this->msg( 'embeddablecontent-add-error-required' )->text();
			}
		}
		return null;
	}

	/**
	 * Creates the member Persons (after the duplicate guard) and fills the
	 * auto-label when the contributor left the label blank.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function commitRecord( array &$record, string $classItemId = '' ): ?string {
		$groupClass = $this->config->agentClasses()['groupOfHumans'] ?? null;
		$class = $classItemId !== ''
			? $classItemId
			: (string)( $record['collectiveClass'] ?? $this->getRequest()->getVal( 'wpclass', '' ) );
		if ( $groupClass === null || $class !== $groupClass ) {
			$record['members'] = '';
			return null;
		}
		$error = $this->applyPersonPanel( 'member', $record, true );
		if ( $error !== null ) {
			return $error;
		}
		if ( trim( (string)( $record['label'] ?? '' ) ) === '' ) {
			$memberIds = ItemIdList::split( (string)( $record['members'] ?? '' ) );
			if ( $memberIds === [] ) {
				return $this->msg( 'embeddablecontent-add-error-required' )->text();
			}
			$record['label'] = $this->groupLabelFor( $memberIds );
		}
		return null;
	}

	/** @param string[] $memberIds */
	private function groupLabelFor( array $memberIds ): string {
		$labels = [];
		foreach ( $memberIds as $id ) {
			try {
				$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $id ) );
			} catch ( \Throwable $e ) {
				$item = null;
			}
			$labels[] = $item instanceof \Wikibase\DataModel\Entity\Item
				? ( EntityLabelText::of( $item ) ?? $id )
				: $id;
		}
		return GroupLabel::fromMembers( $labels );
	}

	private function hasCreateMemberDecisions( string $choicesJson ): bool {
		$choices = json_decode( $choicesJson, true );
		if ( !is_array( $choices ) ) {
			return false;
		}
		foreach ( $choices as $choice ) {
			if ( is_array( $choice ) && ( $choice['action'] ?? '' ) === 'create' ) {
				return true;
			}
		}
		return false;
	}

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

	/**
	 * Class options for the review/manual form: localized human labels
	 * (embeddablecontent-agent-class-*) keyed to the class item ids, sorted
	 * alphabetically in the reader's language (2026-09 UX batch — before,
	 * the raw camelCase keys rendered as the option labels).
	 *
	 * @return array<string,string> label => class item id
	 */
	protected function classOptions(): array {
		$options = [];
		foreach ( $this->config->agentClasses() as $key => $id ) {
			if ( $key === 'person' ) {
				continue;
			}
			$options[$this->msg( 'embeddablecontent-agent-class-' . $key )->text()] = $id;
		}
		return LabelSorter::sortByLabel( $options, $this->getLanguage()->getCode() );
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
