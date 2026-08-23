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
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( 'AddPerson', $config, $client );
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

	protected function reviewFieldSpecs( array $record ): array {
		$deceased = !empty( $record['dateOfDeath'] ) || !empty( $record['placeOfDeath'] );
		// NO editable label field (issue #35): the label is the full name,
		// auto-generated from given/family (primaryLabel).
		return $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) )
			+ [
				'givenName' => $this->plainTextField( 'embeddablecontent-field-givenname', (string)( $record['givenName'] ?? '' ) ),
				'familyName' => $this->plainTextField( 'embeddablecontent-field-familyname', (string)( $record['familyName'] ?? '' ) ),
				'dateOfBirth' => [
					'type' => 'date',
					'label-message' => 'embeddablecontent-field-dateofbirth',
					'default' => (string)( $record['dateOfBirth'] ?? '' ),
				],
				'placeOfBirth' => $this->entityComboboxSpec(
					'embeddablecontent-field-placeofbirth',
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
				'placeOfDeath' => $this->entityComboboxSpec(
					'embeddablecontent-field-placeofdeath',
					(string)( $record['placeOfDeath'] ?? '' ),
					[ 'hide-if' => [ '!==', 'deceased', '1' ] ]
				),
				// Portrait (optional): local-file upload OR pasted URL,
				// toggled by portraitMode; the file is uploaded on create as
				// File:<label>-portrait.<ext> (AddSoftware logo pattern).
				// The license is mandatory only when a portrait is actually
				// provided (enforced in beforeCreate, not by HTMLForm).
				'portraitMode' => [
					'type' => 'radio',
					'label-message' => 'embeddablecontent-person-portrait-mode',
					'options-messages' => [
						'embeddablecontent-person-portrait-mode-file' => 'file',
						'embeddablecontent-person-portrait-mode-url' => 'url',
					],
					'default' => 'file',
				],
				'portraitFile' => [
					'type' => 'file',
					'label-message' => 'embeddablecontent-person-portrait-file',
					'hide-if' => [ '===', 'portraitMode', 'url' ],
				],
				'portraitUrl' => [
					'type' => 'url',
					'label-message' => 'embeddablecontent-person-portrait-url',
					'maxlength' => 500,
					'hide-if' => [ '===', 'portraitMode', 'file' ],
				],
				'portraitLicense' => [
					'type' => 'combobox',
					'options' => $this->config->licenseItems(),
					'label-message' => 'embeddablecontent-person-portrait-license',
					'cssclass' => 'wb-entity-combobox',
					'help' => $this->msg( 'embeddablecontent-person-portrait-license-help' )->parse(),
				],
			]
			+ $this->externalIdFieldSpecs( $record );
	}

	/**
	 * Entity combobox referencing an existing local item (place of birth /
	 * place of death). The default is a harvested QID, corrected by hand.
	 */
	private function entityComboboxSpec( string $messageKey, string $default, array $extra = [] ): array {
		return array_merge( [
			'type' => 'combobox',
			'options' => [],
			'label-message' => $messageKey,
			'cssclass' => 'wb-entity-combobox',
			'default' => $default,
		], $extra );
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
		// statement, P18-aligned) + the image license entity (P275-aligned).
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
		return $specs;
	}

	// ------------------------------------------------------------- portrait

	/** Portrait formats accepted for upload (raster + svg). */
	private const PORTRAIT_EXTENSIONS = [ 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' ];

	/**
	 * Uploads the optional portrait (local file or pasted URL, per the
	 * portraitMode toggle) as File:<label>-portrait.<ext> and records the
	 * file title in $record['portraitFileTitle'] for the image statement.
	 * When a portrait IS provided, its license is mandatory. Idempotent:
	 * an already-uploaded file is left alone. A provided portrait that
	 * cannot be honoured aborts the creation (never silent).
	 *
	 * @param array<string,mixed> $record
	 * @return string|null error message, or null to proceed
	 */
	protected function beforeCreate( array &$record ): ?string {
		$mode = (string)( $record['portraitMode'] ?? 'file' );
		$title = null;
		try {
			if ( $mode === 'url' ) {
				$url = ( new \EmbeddableContent\Content\FragmentSanitizer() )
					->validateUrl( (string)( $record['portraitUrl'] ?? '' ) );
				if ( $url !== null ) {
					$title = $this->uploadPortraitFromUrl( $url, $record );
					if ( $title === null ) {
						return $this->msg( 'embeddablecontent-person-portrait-error', 'unreachable or unsupported URL' )->text();
					}
				}
			} else {
				$title = $this->uploadPortraitFromRequest( $record );
				$upload = $this->getRequest()->getUpload( 'wpPortraitFile' );
				if ( $title === null
					&& $upload instanceof \MediaWiki\Request\WebRequestUpload && $upload->getSize() > 0
				) {
					return $this->msg( 'embeddablecontent-person-portrait-error', 'unsupported file type' )->text();
				}
			}
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-person-portrait-error', $e->getMessage() )->text();
		}
		if ( $title !== null ) {
			$record['portraitFileTitle'] = $title->getDBkey();
			$licenseItem = $this->parseItemId( (string)( $record['portraitLicense'] ?? '' ) );
			if ( $licenseItem === null ) {
				return $this->msg( 'embeddablecontent-person-portrait-license-required' )->text();
			}
			$record['portraitLicense'] = $licenseItem->getSerialization();
		} else {
			$record['portraitLicense'] = '';
		}
		return null;
	}

	/**
	 * Local-file portrait upload (portraitMode=file). Returns the file
	 * title, or null when no file was provided.
	 *
	 * @param array<string,mixed> $record
	 */
	private function uploadPortraitFromRequest( array $record ): ?\MediaWiki\Title\Title {
		$request = $this->getRequest();
		$upload = $request->getUpload( 'wpPortraitFile' );
		if ( !$upload instanceof \MediaWiki\Request\WebRequestUpload
			|| $upload->getSize() <= 0 || $upload->getTempName() === ''
		) {
			return null;
		}
		$tempPath = $upload->getTempName();
		$mime = \MediaWiki\MediaWikiServices::getInstance()
			->getMimeAnalyzer()->guessMimeType( $tempPath, false );
		$destName = $this->portraitDestName( $record, $upload->getName(), $mime );
		if ( $destName === '' ) {
			return null;
		}
		$base = new \MediaWiki\Upload\UploadFromFile();
		$base->initializePathInfo( $destName, $tempPath, $upload->getSize() );
		return $this->performPortraitUpload( $base, $record );
	}

	/**
	 * Paste-URL portrait upload (portraitMode=url) — goes through
	 * UploadFromUrl so the instance's SSRF guards apply.
	 *
	 * @param array<string,mixed> $record
	 */
	private function uploadPortraitFromUrl( string $url, array $record ): ?\MediaWiki\Title\Title {
		if ( !\MediaWiki\Upload\UploadFromUrl::isAllowed( $this->getUser() ) ) {
			return null;
		}
		$path = parse_url( $url, PHP_URL_PATH );
		$name = $path !== false && $path !== null && $path !== '' ? basename( $path ) : 'portrait';
		$mime = $this->mimeFromUrl( $url );
		$destName = $this->portraitDestName( $record, $name, $mime );
		if ( $destName === '' ) {
			return null;
		}
		$base = new \MediaWiki\Upload\UploadFromUrl();
		$base->initialize( $destName, $url );
		$tempPath = $base->getTempPath();
		if ( $tempPath === '' || !is_file( $tempPath ) ) {
			return null;
		}
		return $this->performPortraitUpload( $base, $record );
	}

	/**
	 * Best-effort remote MIME probe (HEAD) for URL-mode portraits; '' when
	 * the probe fails (the destination-name fallback extension applies).
	 */
	private function mimeFromUrl( string $url ): string {
		try {
			$http = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory()
				->create( $url, [], __METHOD__ );
			$http->execute();
			return (string)$http->getResponseHeader( 'Content-Type' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Destination file name "<label>-portrait.<ext>": the extension comes
	 * from the original name, restricted to the portrait whitelist, with a
	 * fallback to the MIME type's canonical extension. Returns '' when the
	 * format is unsupported or the label is unusable.
	 *
	 * @param array<string,mixed> $record
	 */
	private function portraitDestName( array $record, string $originalName, string $mime ): string {
		$ext = strtolower( (string)pathinfo( $originalName, PATHINFO_EXTENSION ) );
		if ( !in_array( $ext, self::PORTRAIT_EXTENSIONS, true ) ) {
			$ext = strtolower( (string)\MediaWiki\MediaWikiServices::getInstance()
				->getMimeAnalyzer()->getExtensionFromMimeTypeOrNull( $mime ) );
		}
		if ( !in_array( $ext, self::PORTRAIT_EXTENSIONS, true ) ) {
			return '';
		}
		$label = (string)preg_replace( '/[#<>\[\]|{}:]/', '', trim( $this->primaryLabel( $record ) ) );
		$label = trim( (string)preg_replace( '/\s+/', ' ', $label ) );
		if ( $label === '' ) {
			return '';
		}
		return "{$label}-portrait.{$ext}";
	}

	/**
	 * Runs verifyUpload + performUpload on a prepared UploadBase. Returns
	 * the file title, or null when no portrait was provided / already
	 * present. A FAILED verification or upload throws — the caller
	 * (beforeCreate) surfaces the reason as a form error.
	 *
	 * @param array<string,mixed> $record
	 * @throws \RuntimeException when the upload is rejected
	 */
	private function performPortraitUpload( \MediaWiki\Upload\UploadBase $base, array $record ): ?\MediaWiki\Title\Title {
		$title = $base->getTitle();
		if ( $title === null ) {
			return null;
		}
		if ( $title->exists() ) {
			return $title; // idempotent: already uploaded on an earlier run
		}
		$verify = $base->verifyUpload();
		if ( ( $verify['status'] ?? null ) !== \MediaWiki\Upload\UploadBase::OK ) {
			$details = $verify['details'] ?? [];
			$detail = is_array( $details ) && $details !== []
				? (string)( $details[0] ?? '' )
				: (string)( $verify['status'] ?? 'rejected' );
			throw new \RuntimeException( 'verifyUpload rejected (' . $detail . ')' );
		}
		$label = $this->primaryLabel( $record );
		$pageText = "Portrait of {$label}, uploaded via Special:AddPerson.";
		$status = $base->performUpload(
			$this->msg( 'embeddablecontent-person-portrait-edit-summary', $label )->inContentLanguage()->text(),
			$pageText,
			false,
			$this->getUser()
		);
		if ( !$status->isOK() ) {
			throw new \RuntimeException( 'performUpload: ' . $status->getMessage()->getParams()[0] ?? 'rejected' );
		}
		return $title;
	}

	// ------------------------------------------------------------- page content
	// Person: page content = the Wikipedia lead intro (Biography section),
	// reviewed on the content step before it is written to the page.

	/** @var \EmbeddableContent\Fetch\WikipediaContentProvider|null lazily built */
	private ?\EmbeddableContent\Fetch\WikipediaContentProvider $wikipedia = null;

	protected function harvestContent( array $record ): array {
		$title = trim( (string)( $record['enwikiTitle'] ?? '' ) );
		if ( $title === '' ) {
			return $record;
		}
		$this->wikipedia ??= new \EmbeddableContent\Fetch\WikipediaContentProvider(
			new \EmbeddableContent\Fetch\CurlHttpClient( [ 'en.wikipedia.org' ] )
		);
		$intro = $this->wikipedia->intro( $title );
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
	 * Only sections with (reviewed) content are rendered: the Wikipedia
	 * Biography when fetched, never an empty scaffold.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageSkeleton( array $record, bool $withMarker = false ): string {
		$marker = $withMarker ? "\n<!-- " . $this->pagePendingMarker() . " -->\n" : "";
		$body = "{{Person}}\n\n";
		$bio = trim( (string)( $record['biography'] ?? '' ) );
		if ( $bio !== '' ) {
			$body .= "== Biography ==\n\n" . $this->attributed( $record, 'biography', $bio ) . "\n\n";
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
