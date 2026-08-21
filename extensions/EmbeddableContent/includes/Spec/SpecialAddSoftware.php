<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use EmbeddableContent\Content\FragmentSanitizer;
use EmbeddableContent\Fetch\ProviderResult;
use EmbeddableContent\Spec\ItemIdList;
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

	public function execute( $subPage ) {
		// Entity comboboxes in the review/manual steps need the autofill
		// module (same wiring as the AddQuotation provenance block).
		$this->getOutput()->addModules( 'ext.embeddableContent.entitysuggest' );
		$parts = explode( '/', trim( (string)$subPage ) );
		if ( ( $parts[0] ?? '' ) === 'complete' && ( $parts[1] ?? '' ) !== '' ) {
			$this->executeComplete( $parts[1] );
			return;
		}
		parent::execute( $subPage );
	}

	/**
	 * Finalizes a just-created FOSS page in a FRESH request: the first
	 * request's parse ran before the sitelink was committed AND the client's
	 * in-process sitelink cache had already cached the negative lookup, so
	 * its wikibase_item page property was left unset. Re-saving the page
	 * here — new process, committed sitelink, empty lookup cache — makes the
	 * re-parse deterministically map the page to the item.
	 *
	 * Idempotent and safe: only touches pages that carry the pending marker
	 * AND whose item is sitelinked to them (both set by the legitimate flow).
	 */
	private function executeComplete( string $itemId ): void {
		$this->setHeaders();
		// The step performs an edit (finalize the page): login-gated like
		// every other step of the flow (the legitimate flow redirects here
		// from the review/manual submit, so the user is already logged in).
		$this->requireLogin();
		try {
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			$item = null;
		}
		$target = null;
		if ( $item instanceof Item && $item->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' ) ) {
			$pageName = $item->getSiteLinkList()->getBySiteId( 'wikibase' )->getPageName();
			$title = Title::newFromText( $pageName );
			if ( $title !== null && $title->exists() ) {
				$page = \MediaWiki\MediaWikiServices::getInstance()
					->getWikiPageFactory()->newFromTitle( $title );
				$current = $page->getContent() !== null ? $page->getContent()->getWikitextForTransclusion() : '';
				if ( strpos( $current, self::PENDING_MARKER ) !== false ) {
					$final = new \MediaWiki\Content\WikitextContent( self::pageSkeleton( false ) );
					$status = $page->doUserEditContent(
						$final,
						$this->getUser(),
						'Completing the page–item link',
						EDIT_UPDATE | EDIT_MINOR
					);
					if ( !$status->isOK() ) {
						// Best-effort: the page still exists with the marker;
						// the contributor can finish the edit by hand.
						$this->getOutput()->redirect( $title->getFullURL() );
						return;
					}
				}
				$target = $title->getFullURL();
			}
		}
		$this->getOutput()->redirect( $target ?? $this->getPageTitle()->getFullURL() );
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

		// Logo (optional): local file upload OR pasted URL, toggled by
		// logoMode; the file is uploaded on create as File:<Name>-logo.<ext>.
		$fields['logoMode'] = [
			'type' => 'radio',
			'label-message' => 'embeddablecontent-software-logo-mode',
			'options-messages' => [
				'embeddablecontent-software-logo-mode-file' => 'file',
				'embeddablecontent-software-logo-mode-url' => 'url',
			],
			'default' => 'file',
		];
		$fields['logoFile'] = [
			'type' => 'file',
			'label-message' => 'embeddablecontent-software-logo-file',
			'hide-if' => [ '===', 'logoMode', 'url' ],
		];
		$fields['logoUrl'] = [
			'type' => 'url',
			'label-message' => 'embeddablecontent-software-logo-url',
			'maxlength' => 500,
			'hide-if' => [ '===', 'logoMode', 'file' ],
		];
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
	 * website/repository/documentation as validated URLs, programming
	 * language as the lexer item, the logo file as an `image` statement,
	 * and the item-typed facts as entity values referencing existing local
	 * items.
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
		$documentation = $sanitizer->validateUrl( (string)( $record['documentationUrl'] ?? '' ) );
		if ( $documentation !== null ) {
			$specs[$this->config->fossPropertyIds()['documentationUrl']] = new StringValue( $documentation );
		}

		// Logo: the uploaded File:<Name>-logo.<ext> page URL (uploaded in
		// beforeCreate, which sets $record['logoFileTitle']).
		if ( !empty( $record['logoFileTitle'] ) ) {
			$fileTitle = \MediaWiki\Title\Title::makeTitle( NS_FILE, (string)$record['logoFileTitle'] );
			if ( $fileTitle !== null ) {
				$specs[$this->config->fossPropertyIds()['image']] = new StringValue( $fileTitle->getFullURL() );
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
		// The sitelink must live in the ENTITY REVISION too (wbgetentities
		// reads sitelinks from the revision, not the table) — saving the
		// item writes both: the revision and, via ItemHandler's secondary
		// data update, the sitelink table.
		// Guard: on create-or-skip reuse the item may already carry the link
		// — never rewrite existing sitelink state.
		$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		if ( $item instanceof Item && !$item->getSiteLinkList()->hasLinkWithSiteId( 'wikibase' ) ) {
			$item->getSiteLinkList()->setNewSiteLink( 'wikibase', $title->getPrefixedText() );
			WikibaseRepo::getEntityStore()->saveEntity(
				$item,
				$this->msg( 'embeddablecontent-software-sitelink-edit-summary', $label )
					->inContentLanguage()->text(),
				$this->getUser(),
				EDIT_UPDATE
			);
			// ALSO write the sitelink table synchronously: the entity save's
			// secondary data update (ItemHandler::saveLinksOfItem) may run
			// deferred, and the finalize step's parse — which happens in the
			// immediately-following request — reads the TABLE. Diff-based, so
			// re-running it here is a harmless no-op when it already landed.
			WikibaseRepo::getStore()->newSiteLinkStore()->saveLinksOfItem( $item );
		}

		if ( !$title->exists() ) {
			$page = \MediaWiki\MediaWikiServices::getInstance()
				->getWikiPageFactory()->newFromTitle( $title );
			// Revision 1 carries a marker: this request's parse runs before
			// the sitelink is durably visible AND the client's in-process
			// sitelink cache would return the cached negative for it — so it
			// cannot set the wikibase_item property. The redirect target
			// below routes through Special:AddSoftware/complete/<id>, which
			// re-saves the page in a FRESH request (committed sitelink,
			// empty cache) and removes the marker.
			$content = new \MediaWiki\Content\WikitextContent(
				self::pageSkeleton( true, (string)( $record['logoFileTitle'] ?? '' ) )
			);
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
			// Round-trip through the finalize step so the page↔item mapping
			// lands deterministically; fall back to the page itself.
			$complete = $this->getPageTitle( 'complete/' . $itemId )->getFullURL();
			return $complete;
		}

		return $title->getFullURL();
	}

	/** Marker left in the first page revision, removed by the finalize step. */
	private const PENDING_MARKER = '__FOSS_LINK_PENDING__';

	/** Logo formats accepted for upload (raster + svg). */
	private const LOGO_EXTENSIONS = [ 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' ];

	/**
	 * Uploads the optional logo (local file or pasted URL, per the logoMode
	 * toggle) as File:<Label>-logo.<ext> and records the file title in
	 * $record['logoFileTitle'] for the image statement and the FOSS: page
	 * skeleton. Idempotent: an already-uploaded file is left alone. When the
	 * user PROVIDED a logo that cannot be uploaded, returns an error message
	 * (aborting the creation — a failed field must never be silent).
	 *
	 * @param array<string,mixed> $record
	 * @return string|null error message, or null to proceed
	 */
	protected function beforeCreate( array &$record ): ?string {
		$mode = (string)( $record['logoMode'] ?? 'file' );
		try {
			if ( $mode === 'url' ) {
				$url = ( new FragmentSanitizer() )->validateUrl( (string)( $record['logoUrl'] ?? '' ) );
				if ( $url !== null ) {
					$title = $this->uploadLogoFromUrl( $url, $record );
					if ( $title === null ) {
						return $this->msg( 'embeddablecontent-software-logo-error', 'unreachable or unsupported URL' )->text();
					}
				}
			} else {
				$title = $this->uploadLogoFromRequest( $record );
				$upload = $this->getRequest()->getUpload( 'wpLogoFile' );
				if ( $title === null
					&& $upload instanceof \MediaWiki\Request\WebRequestUpload && $upload->getSize() > 0
				) {
					return $this->msg( 'embeddablecontent-software-logo-error', 'unsupported file type' )->text();
				}
			}
		} catch ( \Throwable $e ) {
			// The upload layer rejected the logo — surface the reason on the
			// form instead of creating the item without it.
			return $this->msg( 'embeddablecontent-software-logo-error', $e->getMessage() )->text();
		}
		if ( !empty( $title ) ) {
			$record['logoFileTitle'] = $title->getDBkey();
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
		$tempPath = $base->getTempPath();
		if ( $tempPath === '' || !is_file( $tempPath ) ) {
			return null;
		}
		return $this->performLogoUpload( $base, $record );
	}

	/**
	 * Best-effort remote MIME probe (HEAD) for URL-mode logos; '' when the
	 * probe fails (the destination-name fallback extension applies).
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
	 * Computes the destination file name "<label>-logo.<ext>": the extension
	 * comes from the original name, restricted to the logo whitelist, with a
	 * fallback to the MIME type's canonical extension. Returns '' when the
	 * format is unsupported or the label is unusable.
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
	 * surfaces the reason in a warning box instead of silently dropping the
	 * logo.
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
		if ( !$verify->isOK() ) {
			throw new \RuntimeException( 'verifyUpload: ' . implode( '; ', $verify->getErrorsArray() === []
				? [ $verify->getMessage()->getParams()[0] ?? 'rejected' ]
				: array_map( static fn ( $e ) => $e[0], $verify->getErrorsArray() )
			) );
		}
		$label = $this->primaryLabel( $record );
		$pageText = "Logo of {$label}, uploaded via Special:AddSoftware.";
		$status = $base->performUpload(
			$this->msg( 'embeddablecontent-software-logo-edit-summary', $label )->inContentLanguage()->text(),
			$pageText,
			false,
			$this->getUser()
		);
		if ( !$status->isOK() ) {
			throw new \RuntimeException( 'performUpload: ' . $status->getMessage()->getParams()[0] ?? 'rejected' );
		}
		return $title;
	}

	/** Default FOSS: page skeleton — prose lives on the page, facts in the item. */
	private static function pageSkeleton( bool $withMarker = false, string $logoFile = '' ): string {
		$marker = $withMarker ? "\n<!-- " . self::PENDING_MARKER . " -->\n" : "";
		// The logo (when uploaded) is passed to Template:FOSS, which hands it
		// to the infobox so it renders inside the box (see Template:FOSS).
		$logoParam = $logoFile !== ''
			? '|logo=[[File:' . $logoFile . '|frameless|220px|Logo]]'
			: '';
		return "{{FOSS{$logoParam}}}\n\n== Overview ==\n\n<!-- What this software does and who it is for. -->\n\n"
			. "== Features ==\n\n== Alternatives ==\n\n== See also ==\n" . $marker;
	}

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
	 * parseOptionalItemId; ids are deduped.
	 *
	 * @return ItemId[]
	 */
	private function parseOptionalItemIds( string $input ): array {
		$out = [];
		foreach ( ItemIdList::split( $input ) as $candidate ) {
			$id = $this->parseOptionalItemId( $candidate );
			if ( $id !== null ) {
				$out[] = $id;
			}
		}
		return $out;
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
