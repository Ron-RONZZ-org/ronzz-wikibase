<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use EmbeddableContent\Content\FragmentSanitizer;
use EmbeddableContent\Fetch\ProviderResult;
use EmbeddableContent\Spec\ItemIdList;
use Wikibase\DataModel\Entity\EntityIdValue;
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
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( 'AddSoftware', $config, $client );
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
				'documentationUrl' => [
					'type' => 'url',
					'label-message' => 'embeddablecontent-field-documentationurl',
					'default' => (string)( $record['documentationUrl'] ?? '' ),
					'maxlength' => 250,
				],
			];

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
			if ( $harvested !== '' ) {
				// Plain text, HTML-escaped: the label comes from an external
				// API and must never inject markup.
				$fields[$field]['help'] = htmlspecialchars(
					$this->msg( 'embeddablecontent-software-field-harvested', $harvested )->text()
				);
			}
			$fields[$field]['help'] = ( $fields[$field]['help'] ?? '' )
				. $this->msg( $field === 'userInterface'
					? 'embeddablecontent-software-userinterface-help'
					: 'embeddablecontent-entityid-multiple-hint'
				)->parse();
		}

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
			$fields['programmingLanguage']['help'] = htmlspecialchars(
				$this->msg( 'embeddablecontent-software-field-harvested', $harvested )->text()
			);
		}

		// Logo (optional): collapsed behind the "I will upload a logo image
		// for this software" toggle; local file upload OR pasted URL
		// (validated via the shared uploadmeta button), uploaded on create
		// as File:<Name>-logo.<ext>. The license is mandatory when a logo is
		// provided (enforced in beforeCreate); author + license info are
		// free text. All field specs come from the shared ImageUploadHelper
		// (deduplicated with AddPerson's portrait + Special:Upload).
		$fields['logoInclude'] = \EmbeddableContent\Upload\ImageUploadHelper::includeField(
			'logo', 'embeddablecontent-software-logo-include'
		);
		$fields['logoMode'] = \EmbeddableContent\Upload\ImageUploadHelper::modeField(
			'logo',
			'embeddablecontent-software-logo-mode',
			'embeddablecontent-software-logo-mode-file',
			'embeddablecontent-software-logo-mode-url'
		);
		$fields['logoFile'] = \EmbeddableContent\Upload\ImageUploadHelper::fileField(
			'logo', 'embeddablecontent-software-logo-file'
		);
		$fields['logoUrl'] = \EmbeddableContent\Upload\ImageUploadHelper::urlField(
			'logo', 'embeddablecontent-software-logo-url'
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
	protected function statementSpecs( array $record ): array {
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
		$documentation = $sanitizer->validateUrl( (string)( $record['documentationUrl'] ?? '' ) );
		if ( $documentation !== null ) {
			$specs[$this->config->fossPropertyIds()['documentationUrl']] = new StringValue( $documentation );
		}

		// Logo: the uploaded File:<Name>-logo.<ext> page URL (uploaded in
		// beforeCreate, which sets $record['logoFileTitle']) + the license
		// entity + the free-text attribution strings (P2093-aligned image
		// author + unaligned additional license information).
		if ( !empty( $record['logoFileTitle'] ) ) {
			$fileTitle = \MediaWiki\Title\Title::makeTitle( NS_FILE, (string)$record['logoFileTitle'] );
			if ( $fileTitle !== null ) {
				$specs[$this->config->fossPropertyIds()['image']] = new StringValue( $fileTitle->getFullURL() );
			}
		}
		if ( !empty( $record['logoLicense'] ) && isset( $this->config->fossPropertyIds()['license'] ) ) {
			$licenseItem = $this->parseItemId( (string)$record['logoLicense'] );
			if ( $licenseItem !== null ) {
				$specs[$this->config->fossPropertyIds()['license']] = new EntityIdValue( $licenseItem );
			}
		}
		$fossProps = $this->config->fossPropertyIds();
		foreach ( [ 'imageAuthor' => 'logoAuthor', 'imageLicenseInfo' => 'logoLicenseInfo' ] as $propKey => $field ) {
			if ( !empty( $record[$field] ) && isset( $fossProps[$propKey] ) ) {
				$specs[$fossProps[$propKey]] = new StringValue( (string)$record[$field] );
			}
		}

		// Programming language: lexer combobox value (Pygments-style name)
		// → the configured lexer item. The harvested value is a display label
		// ("C++") — alias-map it to the lexer key; unknown names are dropped
		// (the combobox restricts to configured lexers).
		$lexer = strtolower( trim( (string)( $record['programmingLanguage'] ?? '' ) ) );
		$lexer = self::LEXER_ALIASES[$lexer] ?? $lexer;
		if ( $lexer !== '' && isset( $this->config->lexerItemIds()[$lexer] ) ) {
			$specs[$this->config->programmingLanguagePropertyId()][] =
				new EntityIdValue( new ItemId( $this->config->lexerItemIds()[$lexer] ) );
		}

		// Item-typed facts: entity combobox values (existing local items).
		// Each field accepts several comma-separated item ids → one
		// statement per id (a software has several developers/OSes/licenses).
		foreach ( self::FOSS_ENTITY_FIELDS as $field ) {
			$itemIds = $this->parseOptionalItemIds( (string)( $record[$field] ?? '' ) );
			if ( $itemIds === [] ) {
				continue;
			}
			foreach ( $itemIds as $itemId ) {
				$specs[$this->config->fossPropertyIds()[$field]][] = new EntityIdValue( $itemId );
			}
		}

		return $specs + $this->externalIdStatements( $record );
	}

	// ------------------------------------------------------------- classic pages
	// The FOSS:<Name> wiki page + sitelink machinery (issue #26) lives in the
	// base class afterCreate(); this class only declares the page facts.

	protected function pageNamespace(): ?int {
		return defined( 'NS_FOSS' ) ? NS_FOSS : null;
	}

	protected function pagePendingMarker(): string {
		return '__FOSS_LINK_PENDING__';
	}

	protected function pageTemplate(): string {
		return 'FOSS';
	}

	/**
	 * FOSS: page skeleton — prose lives on the page, facts in the item; the
	 * logo (when uploaded) is passed to Template:FOSS, which hands it to the
	 * infobox so it renders inside the box (see Template:FOSS). Only
	 * sections with content are rendered: an == Overview == from the
	 * description when present, never an empty scaffold.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageSkeleton( array $record, bool $withMarker = false ): string {
		$marker = $withMarker ? "\n<!-- " . $this->pagePendingMarker() . " -->\n" : "";
		$logoFile = (string)( $record['logoFileTitle'] ?? '' );
		$logoParam = $logoFile !== ''
			? '|logo=[[File:' . $logoFile . '|frameless|220px|Logo]]'
			: '';
		$body = "{{FOSS{$logoParam}}}\n\n";
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
	 * logo is provided. Idempotent: an already-uploaded file is left alone.
	 * A provided logo that cannot be uploaded returns an error message
	 * (aborting the creation — a failed field must never be silent).
	 * Delegates to the shared ImageUploadHelper (same machinery as
	 * AddPerson's portrait + Special:Upload).
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
				'error' => 'embeddablecontent-software-logo-error',
				'licenseRequired' => 'embeddablecontent-software-logo-license-required',
				'editSummary' => 'embeddablecontent-software-logo-edit-summary',
				'viaPage' => 'Special:AddSoftware',
			],
			fn ( array $record ) => $this->primaryLabel( $record )
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
