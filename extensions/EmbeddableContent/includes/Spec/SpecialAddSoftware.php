<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Content\FragmentSanitizer;
use EmbeddableContent\EntityLabelMatcher;
use EmbeddableContent\Fetch\ProviderResult;
use EmbeddableContent\Spec\ItemIdList;
use Wikibase\DataModel\Entity\ItemId;

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
 * the review step. Each of these fields accepts SEVERAL item ids
 * (comma-separated, e.g. "Q5, Q179"): a software usually has more than one
 * developer, operating system or license, so one statement is written per
 * id. Programming language is the exception: it reuses the AddCodeSnippet
 * lexer combobox (typeable, options from the configured lexers) instead of
 * free item ids. URL/string facts (website, source repository,
 * documentation, logo) are written directly from the corrected record.
 *
 * The optional logo is uploaded to File:<Name>-logo.<ext> (local file or
 * paste URL), linked from the item via the `image` statement and rendered
 * inside the FOSS: page's infobox (Template:FOSS logo parameter).
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddSoftware extends SpecialAddExternalEntity {

	/**
	 * Item-typed FOSS facts written as entity values; each field is an
	 * entity combobox referencing existing local items, accepting several
	 * comma-separated item ids (one statement per id). programmingLanguage
	 * is NOT here — it is a lexer combobox (see reviewFieldSpecs).
	 */
	private const FOSS_ENTITY_FIELDS = [
		'developer', 'license', 'operatingSystem', 'userInterface', 'hasUse',
	];

	/**
	 * Harvested programming-language LABELS ("C++", "C#") → the configured
	 * Pygments-style lexer key ("cpp", "csharp") — the Wikidata harvest
	 * returns display names, the lexer combobox keys are lowercase.
	 */
	private const LEXER_ALIASES = [
		'c++' => 'cpp',
		'c#' => 'csharp',
		'f#' => 'fsharp',
		'shell' => 'sh',
		'javascript' => 'js',
		'typescript' => 'ts',
		'objective-c' => 'objc',
		'html5' => 'html',
		'c++11' => 'cpp',
		'python3' => 'python',
	];

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client,
		string $pageName = 'AddSoftware'
	) {
		parent::__construct( $pageName, $config, $client );
	}

	protected function kindKey(): string {
		return 'software';
	}


	/** The create path delegates to the shared semantic flow service. */
	protected function semanticFlowKindKey(): ?string {
		return 'software';
	}

	/**
	 * The form record → the shared service vocabulary: the repository key
	 * and the lexer combobox value (resolved to its lexer item id).
	 */
	protected function semanticFlowRecord( string $kind, array $record ): array {
		$out = $this->pickServiceFields( $record, \EmbeddableContent\Flow\SemanticEntityFieldMap::fieldsForKind( 'software' ) );
		// The form's website field is keyed 'website'; the service contract
		// calls it officialWebsite.
		if ( !empty( $record['website'] ) ) {
			$out['officialWebsite'] = (string)$record['website'];
		}

		if ( !empty( $record['sourceRepository'] ) ) {
			$out['sourceCodeRepository'] = (string)$record['sourceRepository'];
		}
		unset( $out['sourceRepository'] );
		if ( !empty( $record['programmingLanguage'] ) ) {
			$lexer = strtolower( trim( (string)$record['programmingLanguage'] ) );
			$lexer = self::LEXER_ALIASES[$lexer] ?? $lexer;
			if ( $lexer !== '' && isset( $this->config->lexerItemIds()[$lexer] ) ) {
				$out['programmingLanguage'] = $this->config->lexerItemIds()[$lexer];
			} else {
				unset( $out['programmingLanguage'] );
			}
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
			return new ProviderResult( [], [ 'No software name given' ] );
		}
		return $this->client->searchSoftware( $name );
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

	protected function harvest( string $qid ): ProviderResult {
		return $this->client->harvestSoftware( $qid );
	}

	/**
	 * Message key of the "I will upload a logo image …" toggle. Overridden
	 * by Special:UpdateSoftware with the "(replacing existing)" wording.
	 */
	protected function logoIncludeMsgKey(): string {
		return 'embeddablecontent-software-logo-include';
	}

	protected function reviewFieldSpecs( array $record ): array {
		$fields = $this->labelFieldSpec( 'label', 'embeddablecontent-extsearch-name', (string)( $record['label'] ?? '' ) )
			+ $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) )
			+ [
				'sourceRepository' => [
					'type' => 'url',
					'label-message' => 'embeddablecontent-field-sourcerepository',
					'default' => (string)( $record['sourceRepository'] ?? '' ),
					'maxlength' => 250,
				],
				'documentationUrl' => [
					'type' => 'url',
					'label-message' => 'embeddablecontent-field-documentationurl',
					'default' => (string)( $record['documentationUrl'] ?? '' ),
					'maxlength' => 250,
				],
			]
			+ $this->websiteFieldSpec( $record );

		foreach ( self::FOSS_ENTITY_FIELDS as $field ) {
			$harvested = (string)( $record[$field] ?? '' );
			// Multi-value entity combobox: comma-separated item ids
			// (entitysuggest.js `wb-entity-combobox-multi` mode). The
			// userInterface field additionally explains what the fact means.
			$fields[$field] = [
				'type' => 'combobox',
				'options' => [],
				'label-message' => 'embeddablecontent-field-' . $field,
				'cssclass' => 'wb-entity-combobox wb-entity-combobox-multi',
			];
			if ( $harvested !== '' && preg_match( '/^Q[1-9]\d*$/i', $harvested ) !== 1 ) {
				// Autofill-confirm: a harvested label resolves to an existing
				// item (exact or fuzzy) → prefill the combobox + confirmation
				// banner; no good match → the plain harvested-fact hint.
				$resolved = $this->resolveEntityField( $harvested );
				if ( $resolved !== null ) {
					$fields[$field]['default'] = $resolved['id'];
					$fields[$field]['help'] = $this->entityConfirmHtml(
						'wp' . $field,
						$this->msg( 'embeddablecontent-field-' . $field )->text(),
						$harvested,
						$resolved['label'],
						$resolved['id']
					);
				} else {
					// Plain text, HTML-escaped: the label comes from an
					// external API and must never inject markup.
					$fields[$field]['help'] = htmlspecialchars(
						$this->msg( 'embeddablecontent-software-field-harvested', $harvested )->text()
					);
				}
			}
			$fields[$field]['help'] = ( $fields[$field]['help'] ?? '' )
				. $this->msg( $field === 'userInterface'
					? 'embeddablecontent-software-userinterface-help'
					: 'embeddablecontent-entityid-multiple-hint'
				)->parse();
		}

		// Classic-page kind: FOSS: page or Software: page — asked PER CREATE
		// (the license facts drive the default, the user overrides here).
		// The radio defaults to 'auto' ("follow the license") because the
		// render-time form cannot know the license the user will submit —
		// HTMLForm fills a MISSING radio with its render-time default, so a
		// license-derived default would silently override the posted license
		// (a scripted POST without wppageKind). beforeCreate resolves 'auto'
		// against the SUBMITTED license; 'foss'/'software' are explicit.
		$fields['pageKind'] = [
			'type' => 'radio',
			'label-message' => 'embeddablecontent-software-pagekind',
			'options-messages' => [
				'embeddablecontent-software-pagekind-auto' => 'auto',
				'embeddablecontent-software-pagekind-foss' => 'foss',
				'embeddablecontent-software-pagekind-software' => 'software',
			],
			'default' => 'auto',
			'help-message' => 'embeddablecontent-software-pagekind-help',
		];

		// Programming language: the same typeable lexer combobox as
		// Special:AddCodeSnippet (options = configured lexers) — a picker
		// beats free item ids, and the instance's 80+ language items are
		// exactly the lexer set.
		$lexers = [];
		foreach ( array_keys( $this->config->lexerItemIds() ) as $lexer ) {
			$lexers[$lexer] = $lexer;
		}
		$harvested = (string)( $record['programmingLanguage'] ?? '' );
		$fields['programmingLanguage'] = [
			'type' => 'combobox',
			'options' => $lexers,
			'label-message' => 'embeddablecontent-field-programmingLanguage',
		];
		if ( $harvested !== '' ) {
			// Autofill-confirm against the known lexer labels (the harvested
			// language name → the configured lexer key).
			$best = EntityLabelMatcher::bestMatchFromLabels( $harvested, $lexers );
			if ( $best !== null ) {
				$fields['programmingLanguage']['default'] = $best['label'];
				$fields['programmingLanguage']['help'] = $this->entityConfirmHtml(
					'wpprogrammingLanguage',
					$this->msg( 'embeddablecontent-field-programmingLanguage' )->text(),
					$harvested,
					$best['label'],
					$this->config->lexerItemIds()[$best['label']] ?? ''
				);
			} else {
				$fields['programmingLanguage']['help'] = htmlspecialchars(
					$this->msg( 'embeddablecontent-software-field-harvested', $harvested )->text()
				);
			}
		}

		// Logo (optional): collapsed behind the "I will upload a logo image
		// for this software" toggle; local file upload OR pasted URL
		// (validated via the shared uploadmeta button), uploaded on create
		// as File:<Name>-logo.<ext>. The license is mandatory when a logo is
		// provided (enforced in beforeCreate); author + license info are
		// free text. All field specs come from the shared ImageUploadHelper
		// (deduplicated with AddPerson's portrait + Special:Upload).
		$fields['logoInclude'] = \EmbeddableContent\Upload\ImageUploadHelper::includeField(
			'logo', $this->logoIncludeMsgKey()
		);
		$fields['logoMode'] = \EmbeddableContent\Upload\ImageUploadHelper::modeField(
			'logo',
			'embeddablecontent-software-logo-mode',
			'embeddablecontent-software-logo-mode-file',
			'embeddablecontent-software-logo-mode-url',
			'embeddablecontent-upload-mode-existing'
		);
		$fields['logoFile'] = \EmbeddableContent\Upload\ImageUploadHelper::fileField(
			'logo', 'embeddablecontent-software-logo-file'
		);
		$fields['logoUrl'] = \EmbeddableContent\Upload\ImageUploadHelper::urlField(
			'logo', 'embeddablecontent-software-logo-url',
			$this->msg( 'embeddablecontent-software-logo-license' )->text()
		);
		$fields['logoExisting'] = \EmbeddableContent\Upload\ImageUploadHelper::existingField(
			'logo', 'embeddablecontent-upload-existing'
		);
		$fields['logoLicense'] = \EmbeddableContent\Upload\ImageUploadHelper::licenseField(
			'logo',
			'embeddablecontent-software-logo-license',
			'embeddablecontent-software-logo-license-help',
			$this->config
		);
		$fields['logoAuthor'] = \EmbeddableContent\Upload\ImageUploadHelper::authorField(
			'logo', 'embeddablecontent-software-logo-author'
		);
		$fields['logoLicenseInfo'] = \EmbeddableContent\Upload\ImageUploadHelper::licenseInfoField(
			'logo', 'embeddablecontent-software-logo-license-info'
		);
		return $fields;
	}

	/**
	 * FOSS statement specs from a (harvested or hand-entered) record:
	 * website/repository/documentation as validated URLs, programming
	 * language as the lexer item, the logo file as an `image` statement,
	 * the item-typed facts as entity values referencing existing local
	 * items, plus the authority external ids (base contract).
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,\Wikibase\DataModel\DataValue> property id => DataValue
	 */

	// ------------------------------------------------------------- classic pages
	// The FOSS:<Name> wiki page + sitelink machinery (issue #26) lives in the
	// base class afterCreate(); this class only declares the page facts.

	protected function pageNamespace(): ?int {
		return defined( 'NS_FOSS' ) ? NS_FOSS : null;
	}

	/**
	 * The classic page lands in the FOSS: namespace (free/open-source
	 * license) or the Software: namespace (everything else) — the page-kind
	 * radio on the review form decides, defaulting from the license facts.
	 * Null (namespace not registered — e.g. dev before the config block) is
	 * the item-only fallback.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageNamespaceForRecord( array $record ): ?int {
		if ( ( $record['pageKind'] ?? '' ) === 'software' ) {
			return defined( 'NS_SOFTWARE' ) ? NS_SOFTWARE : null;
		}
		return parent::pageNamespaceForRecord( $record );
	}

	protected function pageTemplate(): string {
		return 'FOSS';
	}

	/**
	 * @param array<string,mixed> $record
	 */
	protected function pageTemplateForRecord( array $record ): string {
		return ( $record['pageKind'] ?? '' ) === 'software' ? 'Software' : parent::pageTemplateForRecord( $record );
	}

	protected function pagePendingMarker(): string {
		return '__FOSS_LINK_PENDING__';
	}

	/**
	 * FOSS:/Software: page skeleton — prose lives on the page, facts in the
	 * item; the logo (when uploaded) is passed to the kind's template
	 * (Template:FOSS / Template:Software), which hands it to the infobox so
	 * it renders inside the box. Only sections with content are rendered:
	 * an == Overview == from the description when present, never an empty
	 * scaffold.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageSkeleton( array $record, bool $withMarker = false ): string {
		$marker = $withMarker ? "\n<!-- " . $this->pagePendingMarker() . " -->\n" : "";
		$logoFile = (string)( $record['logoFileTitle'] ?? '' );
		$logoParam = $logoFile !== ''
			? '|logo=[[File:' . $logoFile . '|frameless|220px|Logo]]'
			: '';
		$template = $this->pageTemplateForRecord( $record );
		$body = "{{{$template}{$logoParam}}}\n\n";
		$overview = trim( (string)( $record['description'] ?? '' ) );
		if ( $overview !== '' ) {
			$body .= "== Overview ==\n\n{$overview}\n\n";
		}
		return $body . $marker;
	}

	protected function pageEditSummary( string $label ): string {
		return $this->msg( 'embeddablecontent-software-page-edit-summary', $label )->inContentLanguage()->text();
	}

	protected function pageSitelinkSummary( string $label ): string {
		return $this->msg( 'embeddablecontent-software-sitelink-edit-summary', $label )->inContentLanguage()->text();
	}

	/**
	 * Uploads the optional logo (local file or pasted URL, per the logoMode
	 * toggle, behind the logoInclude toggle) as File:<Label>-logo.<ext> and
	 * records the file title in $record['logoFileTitle'] for the image
	 * statement and the FOSS: page skeleton. The license is mandatory when a
	 * NEW logo is provided (recorded on the file's own image item + File:
	 * page); reusing an existing file needs no license. Idempotent: an
	 * already-uploaded file is left alone. A provided logo that cannot be
	 * uploaded returns an error message (aborting the creation — a failed
	 * field must never be silent). Delegates to the shared ImageUploadHelper
	 * (same machinery as AddPerson's portrait + Special:Upload).
	 *
	 * @param array<string,mixed> $record
	 * @return string|null error message, or null to proceed
	 */
	protected function beforeCreate( array &$record ): ?string {
		// Resolve the page-kind radio: 'auto' (the default — the render-time
		// form cannot know the license the user submits) or an ABSENT value
		// (a scripted POST) follows the SUBMITTED license; 'foss'/'software'
		// are the user's explicit override and pass through untouched.
		$pageKind = (string)( $record['pageKind'] ?? '' );
		if ( $pageKind === '' || $pageKind === 'auto' ) {
			$record['pageKind'] = \EmbeddableContent\Flow\SoftwarePageKind::defaultFor(
				$this->config,
				(string)( $record['license'] ?? '' )
			);
		}
		return \EmbeddableContent\Upload\ImageUploadHelper::handleUpload(
			'logo',
			$record,
			$this->getContext(),
			$this->getUser(),
			[
				'error' => 'embeddablecontent-software-logo-error',
				'licenseRequired' => 'embeddablecontent-software-logo-license-required',
				'editSummary' => 'embeddablecontent-software-logo-edit-summary',
			],
			fn ( array $record ) => $this->primaryLabel( $record ),
			$this->config
		);
	}

	// ------------------------------------------------------------- classic pages
	// The FOSS:<Name> wiki page + sitelink machinery (issue #26) lives in the
	// base class afterCreate(); this class only declares the page facts. The
	// logo upload machinery itself (field specs, file/URL upload, dest
	// naming, verify+performUpload, page text) lives once in
	// ImageUploadHelper — AddPerson's portrait and Special:Upload use it too.

	protected function classOptions(): array {
		return $this->config->fossClasses();
	}

	protected function defaultClassItemId( array $record ): ?string {
		$fossClasses = $this->config->fossClasses();
		return $fossClasses['foss'] ?? null;
	}

	/**
	 * Parses a possibly multi-valued entity-field input (comma/semicolon/
	 * whitespace-separated item ids) into validated ItemIds. Invalid
	 * elements are skipped — same lenient contract as the single-value
	 * parseItemId; ids are deduped.
	 *
	 * @return ItemId[]
	 */
	private function parseOptionalItemIds( string $input ): array {
		$out = [];
		foreach ( ItemIdList::split( $input ) as $candidate ) {
			$id = $this->parseItemId( $candidate );
			if ( $id !== null ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
