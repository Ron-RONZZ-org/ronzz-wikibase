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
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( 'AddCollective', $config, $client );
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

	protected function reviewFieldSpecs( array $record ): array {
		return $this->labelFieldSpec( 'label', 'embeddablecontent-add-label', (string)( $record['label'] ?? '' ) )
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
			+ $this->logoFieldSpecs()
			+ $this->externalIdFieldSpecs( $record );
	}

	// ------------------------------------------------------------- logo

	/**
	 * Optional logo (issue follow-up, the AddSoftware logo pattern): local
	 * file upload OR pasted URL, toggled by logoMode; uploaded on create as
	 * File:<label>-logo.<ext> with a mandatory license (the AddPerson
	 * portrait contract).
	 *
	 * @return array<string,mixed> fieldname => descriptor
	 */
	private function logoFieldSpecs(): array {
		return [
			'logoMode' => [
				'type' => 'radio',
				'label-message' => 'embeddablecontent-collective-logo-mode',
				'options-messages' => [
					'embeddablecontent-software-logo-mode-file' => 'file',
					'embeddablecontent-software-logo-mode-url' => 'url',
				],
				'default' => 'file',
			],
			'logoFile' => [
				'type' => 'file',
				'label-message' => 'embeddablecontent-collective-logo-file',
				'hide-if' => [ '===', 'logoMode', 'url' ],
			],
			'logoUrl' => [
				'type' => 'url',
				'label-message' => 'embeddablecontent-collective-logo-url',
				'maxlength' => 500,
				'hide-if' => [ '===', 'logoMode', 'file' ],
			],
			'logoLicense' => [
				'type' => 'combobox',
				'options' => $this->config->licenseItems(),
				'label-message' => 'embeddablecontent-collective-logo-license',
				'cssclass' => 'wb-entity-combobox',
				'help' => $this->msg( 'embeddablecontent-collective-logo-license-help' )->parse(),
			],
		];
	}

	/** Logo formats accepted for upload (raster + svg). */
	private const LOGO_EXTENSIONS = [ 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' ];

	/**
	 * Uploads the optional logo (local file or pasted URL, per the logoMode
	 * toggle) as File:<label>-logo.<ext> and records the file title in
	 * $record['logoFileTitle'] for the image statement. The logo license is
	 * mandatory when a logo IS provided (the AddPerson portrait contract);
	 * a provided logo that cannot be honoured aborts the creation (never
	 * silent).
	 *
	 * @param array<string,mixed> $record
	 * @return string|null error message, or null to proceed
	 */
	protected function beforeCreate( array &$record ): ?string {
		$mode = (string)( $record['logoMode'] ?? 'file' );
		$title = null;
		try {
			if ( $mode === 'url' ) {
				$url = ( new \EmbeddableContent\Content\FragmentSanitizer() )
					->validateUrl( (string)( $record['logoUrl'] ?? '' ) );
				if ( $url !== null ) {
					$title = $this->uploadLogoFromUrl( $url, $record );
					if ( $title === null ) {
						return $this->msg( 'embeddablecontent-collective-logo-error', 'unreachable or unsupported URL' )->text();
					}
				}
			} else {
				$title = $this->uploadLogoFromRequest( $record );
				$upload = $this->getRequest()->getUpload( 'wpLogoFile' );
				if ( $title === null
					&& $upload instanceof \MediaWiki\Request\WebRequestUpload && $upload->getSize() > 0
				) {
					return $this->msg( 'embeddablecontent-collective-logo-error', 'unsupported file type' )->text();
				}
			}
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-collective-logo-error', $e->getMessage() )->text();
		}
		if ( $title !== null ) {
			$record['logoFileTitle'] = $title->getDBkey();
			$licenseItem = $this->parseItemId( (string)( $record['logoLicense'] ?? '' ) );
			if ( $licenseItem === null ) {
				return $this->msg( 'embeddablecontent-collective-logo-license-required' )->text();
			}
			$record['logoLicense'] = $licenseItem->getSerialization();
		} else {
			$record['logoLicense'] = '';
		}
		return null;
	}

	/**
	 * Local-file logo upload (logoMode=file). Returns the file title, or
	 * null when no file was provided.
	 *
	 * @param array<string,mixed> $record
	 */
	private function uploadLogoFromRequest( array $record ): ?\MediaWiki\Title\Title {
		$request = $this->getRequest();
		$upload = $request->getUpload( 'wpLogoFile' );
		if ( !$upload instanceof \MediaWiki\Request\WebRequestUpload
			|| $upload->getSize() <= 0 || $upload->getTempName() === ''
		) {
			return null;
		}
		$tempPath = $upload->getTempName();
		$mime = \MediaWiki\MediaWikiServices::getInstance()
			->getMimeAnalyzer()->guessMimeType( $tempPath, false );
		$destName = $this->logoDestName( $record, $upload->getName(), $mime );
		if ( $destName === '' ) {
			return null;
		}
		$base = new \MediaWiki\Upload\UploadFromFile();
		$base->initializePathInfo( $destName, $tempPath, $upload->getSize() );
		return $this->performLogoUpload( $base, $record );
	}

	/**
	 * Paste-URL logo upload (logoMode=url) — goes through UploadFromUrl so
	 * the instance's SSRF guards (IsUploadAllowedFromUrl) apply.
	 *
	 * @param array<string,mixed> $record
	 */
	private function uploadLogoFromUrl( string $url, array $record ): ?\MediaWiki\Title\Title {
		if ( !\MediaWiki\Upload\UploadFromUrl::isAllowed( $this->getUser() ) ) {
			return null;
		}
		$path = parse_url( $url, PHP_URL_PATH );
		$name = $path !== false && $path !== null && $path !== '' ? basename( $path ) : 'logo';
		$mime = $this->mimeFromUrl( $url );
		$destName = $this->logoDestName( $record, $name, $mime );
		if ( $destName === '' ) {
			return null;
		}
		$base = new \MediaWiki\Upload\UploadFromUrl();
		$base->initialize( $destName, $url );
		// initialize() only creates the EMPTY temp file — the download
		// itself happens in fetchFile() (UploadFromUrl streams the body and
		// follows redirects, SSRF-validating each hop). Without it
		// verifyUpload() sees a zero-size file and rejects the upload as
		// EMPTY_FILE (status 3).
		$status = $base->fetchFile();
		$tempPath = $base->getTempPath();
		if ( !$status->isGood() || $tempPath === '' || !is_file( $tempPath ) ) {
			return null;
		}
		return $this->performLogoUpload( $base, $record );
	}

	/** Best-effort remote MIME probe (HEAD) for URL-mode logos; '' on failure. */
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
	 * Destination file name "<label>-logo.<ext>": the extension comes from
	 * the original name, restricted to the logo whitelist, with a fallback
	 * to the MIME type's canonical extension. Returns '' when the format is
	 * unsupported or the label is unusable.
	 *
	 * @param array<string,mixed> $record
	 */
	private function logoDestName( array $record, string $originalName, string $mime ): string {
		$ext = strtolower( (string)pathinfo( $originalName, PATHINFO_EXTENSION ) );
		if ( !in_array( $ext, self::LOGO_EXTENSIONS, true ) ) {
			$ext = strtolower( (string)\MediaWiki\MediaWikiServices::getInstance()
				->getMimeAnalyzer()->getExtensionFromMimeTypeOrNull( $mime ) );
		}
		if ( !in_array( $ext, self::LOGO_EXTENSIONS, true ) ) {
			return '';
		}
		$label = (string)preg_replace( '/[#<>\[\]|{}:]/', '', trim( $this->primaryLabel( $record ) ) );
		$label = trim( (string)preg_replace( '/\s+/', ' ', $label ) );
		if ( $label === '' ) {
			return '';
		}
		return "{$label}-logo.{$ext}";
	}

	/**
	 * Runs verifyUpload + performUpload on a prepared UploadBase. Returns
	 * the file title, or null when no logo was provided / already present.
	 * A FAILED verification or upload throws — the caller (beforeCreate)
	 * surfaces the reason as a form error.
	 *
	 * @param array<string,mixed> $record
	 * @throws \RuntimeException when the upload is rejected
	 */
	private function performLogoUpload( \MediaWiki\Upload\UploadBase $base, array $record ): ?\MediaWiki\Title\Title {
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
		$pageText = "Logo of {$label}, uploaded via Special:AddCollective.";
		$status = $base->performUpload(
			$this->msg( 'embeddablecontent-collective-logo-edit-summary', $label )->inContentLanguage()->text(),
			$pageText,
			false,
			$this->getUser()
		);
		if ( !$status->isOK() ) {
			throw new \RuntimeException( 'performUpload: ' . ( $status->getMessage()->getParams()[0] ?? 'rejected' ) );
		}
		return $title;
	}

	/**
	 * Collective statements: the base authority/citation facts plus the
	 * optional parent organization entity link and the logo (uploaded
	 * File: URL on `image` + its license entity).
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
		if ( !empty( $record['logoFileTitle'] ) && isset( $props['image'] ) ) {
			$fileTitle = \MediaWiki\Title\Title::makeTitle( NS_FILE, (string)$record['logoFileTitle'] );
			if ( $fileTitle !== null ) {
				$specs[$props['image']] = new \DataValues\StringValue( $fileTitle->getFullURL() );
			}
		}
		if ( !empty( $record['logoLicense'] ) && isset( $props['license'] ) ) {
			$licenseItem = $this->parseItemId( (string)$record['logoLicense'] );
			if ( $licenseItem !== null ) {
				$specs[$props['license']] = new \Wikibase\DataModel\Entity\EntityIdValue( $licenseItem );
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
	 * Collective: page skeleton — only sections with content are rendered
	 * (collectives currently fetch none, so the page is the template alone;
	 * the contributor adds sections by editing).
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageSkeleton( array $record, bool $withMarker = false ): string {
		$marker = $withMarker ? "\n<!-- " . $this->pagePendingMarker() . " -->\n" : "";
		return "{{Collective}}\n\n" . $marker;
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
