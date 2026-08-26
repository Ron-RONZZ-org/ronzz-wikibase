<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Upload;

use EmbeddableContent\Content\FragmentSanitizer;
use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Context\IContextSource;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadFromFile;
use MediaWiki\Upload\UploadFromUrl;
use MediaWiki\User\User;
use Throwable;

/**
 * Shared portrait/logo upload machinery — the deduplication target of the
 * upload-enhancements work.
 *
 * Special:AddPerson (portrait) and Special:AddSoftware (logo) each used to
 * carry ~150 lines of private upload code (mode toggle, UploadFromUrl with
 * the fetchFile() fix, dest naming, verify+performUpload, license
 * requirement). This helper owns that logic once:
 *
 *  - field specs for the whole section, including the new "I will upload an
 *    image for this entity" collapse toggle, the free-text author and
 *    additional-license-info fields, and the `wb-uploadmeta` wiring span
 *    (validate button, driven by resources/uploadmeta.js — browser-side
 *    Wikimedia metadata fetch + the 429 blob fallback);
 *  - the beforeCreate upload path (file or URL, idempotent, error-surfacing);
 *  - pure helpers (dest naming, wiring config) for unit tests.
 *
 * The Special:Upload item-per-upload reuses the same field vocabulary
 * (image/license/imageAuthor/imageLicenseInfo statements).
 *
 * @license GPL-2.0-or-later
 */

/**
 * Field keys generated for a prefix ("portrait" | "logo"):
 *   {$prefix}Include      — "I will upload a …" toggle (hides the section)
 *   {$prefix}Mode         — radio file|url|existing (no default)
 *   {$prefix}File         — browser file input (hide-if !file)
 *   {$prefix}Url          — pasted URL (hide-if !url)
 *   {$prefix}Existing     — File: search combobox (hide-if !existing)
 *   {$prefix}License      — entity combobox (config licenseItems)
 *   {$prefix}Author       — free-text author
 *   {$prefix}LicenseInfo  — free-text additional license information
 *   {$prefix}FileTitle    — (set by handleUpload) the uploaded/ reused File: DB key
 */
final class ImageUploadHelper {

	/** Image formats accepted for portrait/logo upload (raster + svg). */
	public const IMAGE_EXTENSIONS = [ 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' ];

	/**
	 * Message keys for the section (each page passes its own — "Portrait"
	 * vs "Logo" wording is preserved).
	 *
	 * @var array{include:string,mode:string,modeFile:string,modeUrl:string,file:string,url:string,license:string,licenseHelp:string,author:string,licenseInfo:string,error:string,licenseRequired:string,editSummary:string}
	 */
	public const MSG_KEYS = [
		'include', 'mode', 'modeFile', 'modeUrl', 'file', 'url',
		'license', 'licenseHelp', 'author', 'licenseInfo',
		'error', 'licenseRequired', 'editSummary',
	];

	// ------------------------------------------------------------- field specs

	/**
	 * The "I will upload a {portrait/logo image} for this {entity}" toggle.
	 * Every other section field hides unless it is checked.
	 */
	public static function includeField( string $prefix, string $msgKey ): array {
		return [
			'type' => 'check',
			'label-message' => $msgKey,
			'default' => false,
		];
	}

	/**
	 * Radio choosing the image source: file from the device | pasted URL |
	 * reuse an existing file on this wiki. NO default is selected — the
	 * user picks the mode themselves (the file/url/existing inputs stay
	 * hidden until then; each input's hide-if is `!== Mode, <value>`).
	 */
	public static function modeField( string $prefix, string $modeMsg, string $fileMsg, string $urlMsg, string $existingMsg ): array {
		return [
			'type' => 'radio',
			'label-message' => $modeMsg,
			'options-messages' => [ $fileMsg => 'file', $urlMsg => 'url', $existingMsg => 'existing' ],
			'default' => '',
			'hide-if' => [ '===', $prefix . 'Include', '' ],
		];
	}

	/** Local-file input (mode=file; also the browser-blob fallback target). */
	public static function fileField( string $prefix, string $msgKey ): array {
		return [
			'type' => 'file',
			'label-message' => $msgKey,
			'hide-if' => [ 'OR', [ '===', $prefix . 'Include', '' ], [ '!==', $prefix . 'Mode', 'file' ] ],
		];
	}

	/**
	 * The pasted-URL field + the validate-button wiring span (rendered
	 * right after the field; uploadmeta.js reads its data-config). The
	 * license label rides along for the autofill confirmation copy.
	 */
	public static function urlField( string $prefix, string $msgKey, string $licenseLabel = '' ): array {
		return [
			'type' => 'url',
			'label-message' => $msgKey,
			'maxlength' => 500,
			'hide-if' => [ 'OR', [ '===', $prefix . 'Include', '' ], [ '!==', $prefix . 'Mode', 'url' ] ],
			'help-raw' => self::wiringSpan( $prefix, $licenseLabel ),
		];
	}

	/**
	 * The "reuse an existing file on this wiki" combobox (mode=existing):
	 * an OOUI ComboBoxInputWidget over the instance's File: namespace
	 * (resources/fileselect.js wires the autocomplete + thumbnail
	 * suggestions + the preview). The submitted value is a "File:<name>"
	 * title; the server validates the file exists in beforeCreate. The
	 * preview span in the help slot is filled by fileselect.js.
	 */
	public static function existingField( string $prefix, string $msgKey ): array {
		return [
			'type' => 'combobox',
			'options' => [],
			'label-message' => $msgKey,
			'cssclass' => 'wb-file-combobox',
			'maxlength' => 255,
			'hide-if' => [ 'OR', [ '===', $prefix . 'Include', '' ], [ '!==', $prefix . 'Mode', 'existing' ] ],
			'help' => '<span class="wb-file-preview"></span>',
		];
	}

	/** Semantic license combobox (config licenseItems + entity search). */
	public static function licenseField( string $prefix, string $msgKey, string $helpMsg, EmbeddableContentConfig $config ): array {
		return [
			'type' => 'combobox',
			'options' => $config->licenseItems(),
			'label-message' => $msgKey,
			'cssclass' => 'wb-entity-combobox',
			// MW 1.46: 'help' is raw HTML (deprecated); 'help-message' is the
			// key form — the bare key string rendered verbatim before.
			'help-message' => $helpMsg,
			'hide-if' => [ '===', $prefix . 'Include', '' ],
		];
	}

	public static function authorField( string $prefix, string $msgKey ): array {
		return [
			'type' => 'text',
			'label-message' => $msgKey,
			'maxlength' => 250,
			'hide-if' => [ '===', $prefix . 'Include', '' ],
		];
	}

	public static function licenseInfoField( string $prefix, string $msgKey ): array {
		return [
			'type' => 'text',
			'label-message' => $msgKey,
			'maxlength' => 250,
			'hide-if' => [ '===', $prefix . 'Include', '' ],
		];
	}

	/**
	 * The validate-button wiring span. uploadmeta.js reads data-config and
	 * injects the button next to the URL field; on submit it converts a
	 * Wikimedia URL-mode upload into a browser-supplied file (the 429 blob
	 * fallback) and records the original URL in wbUploadmetaSourceUrl. The
	 * license label ('' = none) is the confirmation-banner field name.
	 */
	public static function wiringSpan( string $prefix, string $licenseLabel = '' ): string {
		$config = [
			'urlField' => 'wp' . $prefix . 'Url',
			'fileField' => 'wp' . $prefix . 'File',
			'modeField' => 'wp' . $prefix . 'Mode',
			'fileMode' => 'file',
			'licenseLabel' => $licenseLabel,
			'targets' => [
				'author' => 'wp' . $prefix . 'Author',
				'license' => 'wp' . $prefix . 'License',
				'licenseInfo' => 'wp' . $prefix . 'LicenseInfo',
			],
		];
		return '<span class="wb-uploadmeta" data-config="'
			. htmlspecialchars( (string)json_encode( $config ), ENT_QUOTES, 'UTF-8' )
			. '"></span>';
	}

	// ------------------------------------------------------------- upload path

	/**
	 * Runs the section's upload (the beforeCreate body): honours the
	 * include-toggle, dispatches file|url|existing (file = browser upload,
	 * url = SSRF-guarded UploadFromUrl, existing = reuse a File: page on
	 * this wiki — no upload), uploads as File:<label>-<suffix>.<ext>,
	 * enforces the license, and records the outcome on $record
	 * ("{$prefix}FileTitle", "{$prefix}License", "{$prefix}Author",
	 * "{$prefix}LicenseInfo"). Returns an error message (or null to
	 * proceed) — a provided image that cannot be honoured is NEVER silently
	 * skipped.
	 *
	 * The URL-mode path goes through UploadFromUrl (SSRF-guarded) with the
	 * fetchFile() fix; the browser-blob fallback (uploadmeta.js) converts a
	 * Wikimedia URL into a plain file upload BEFORE this runs — the original
	 * URL rides along in wbUploadmetaSourceUrl and is recorded on the file
	 * page for provenance.
	 *
	 * @param array<string,mixed> $record
	 * @param array{error:string,licenseRequired:string,editSummary:string} $msgKeys
	 * @param callable(array):string $primaryLabel record → item label
	 */
	public static function handleUpload(
		string $prefix,
		array &$record,
		IContextSource $context,
		User $user,
		array $msgKeys,
		callable $primaryLabel
	): ?string {
		$error = static function ( string $reason ) use ( $context, $msgKeys ): string {
			return $context->msg( $msgKeys['error'], $reason )->text();
		};
		// The section is opt-in: nothing to do when the toggle is unchecked.
		if ( empty( $record[$prefix . 'Include'] ) ) {
			$record[$prefix . 'FileTitle'] = '';
			$record[$prefix . 'License'] = '';
			$record[$prefix . 'Author'] = '';
			$record[$prefix . 'LicenseInfo'] = '';
			return null;
		}

		$mode = (string)( $record[$prefix . 'Mode'] ?? '' );
		$title = null;
		try {
			if ( $mode === 'url' ) {
				$url = ( new FragmentSanitizer() )->validateUrl( (string)( $record[$prefix . 'Url'] ?? '' ) );
				if ( $url !== null ) {
					$title = self::uploadFromUrl( $prefix, $url, $record, $context, $user, $msgKeys, $primaryLabel );
					if ( $title === null ) {
						return $error( 'unreachable or unsupported URL' );
					}
				}
			} elseif ( $mode === 'existing' ) {
				// Reuse an existing File: page on this wiki — no upload.
				$title = self::reuseExistingFile( $prefix, $record );
				if ( $title === null ) {
					return $error( 'no such file on this wiki' );
				}
			} else {
				$title = self::uploadFromRequest( $prefix, $record, $context, $user, $msgKeys, $primaryLabel );
				$upload = $context->getRequest()->getUpload( 'wp' . $prefix . 'File' );
				if ( $title === null
					&& $upload instanceof \MediaWiki\Request\WebRequestUpload && $upload->getSize() > 0
				) {
					return $error( 'unsupported file type' );
				}
			}
		} catch ( Throwable $e ) {
			return $error( $e->getMessage() );
		}

		if ( $title !== null ) {
			$record[$prefix . 'FileTitle'] = $title->getDBkey();
			$licenseItem = self::parseItemId( (string)( $record[$prefix . 'License'] ?? '' ) );
			if ( $licenseItem === null ) {
				return $context->msg( $msgKeys['licenseRequired'] )->text();
			}
			$record[$prefix . 'License'] = $licenseItem->getSerialization();
		} else {
			$record[$prefix . 'FileTitle'] = '';
			$record[$prefix . 'License'] = '';
		}
		return null;
	}

	/** Local-file upload (mode=file; also the blob-fallback endpoint). */
	private static function uploadFromRequest(
		string $prefix,
		array &$record,
		IContextSource $context,
		User $user,
		array $msgKeys,
		callable $primaryLabel
	): ?Title {
		$upload = $context->getRequest()->getUpload( 'wp' . $prefix . 'File' );
		if ( !$upload instanceof \MediaWiki\Request\WebRequestUpload
			|| $upload->getSize() <= 0 || $upload->getTempName() === ''
		) {
			return null;
		}
		$tempPath = $upload->getTempName();
		$mime = MediaWikiServices::getInstance()->getMimeAnalyzer()->guessMimeType( $tempPath, false );
		$mimeExtension = (string)( MediaWikiServices::getInstance()
			->getMimeAnalyzer()->getExtensionFromMimeTypeOrNull( $mime ) ?? '' );
		$destName = self::destName(
			$primaryLabel( $record ),
			$prefix,
			$upload->getName(),
			$mimeExtension,
			self::IMAGE_EXTENSIONS
		);
		if ( $destName === '' ) {
			return null;
		}
		$base = new UploadFromFile();
		$base->initializePathInfo( $destName, $tempPath, $upload->getSize() );
		return self::performUpload( $prefix, $base, $record, $context, $user, $msgKeys, $primaryLabel );
	}

	/** Pasted-URL upload — UploadFromUrl so the instance's SSRF guards apply. */
	private static function uploadFromUrl(
		string $prefix,
		string $url,
		array &$record,
		IContextSource $context,
		User $user,
		array $msgKeys,
		callable $primaryLabel
	): ?Title {
		if ( !UploadFromUrl::isAllowed( $user ) ) {
			return null;
		}
		$path = parse_url( $url, PHP_URL_PATH );
		$name = $path !== false && $path !== null && $path !== '' ? basename( $path ) : $prefix;
		$mime = self::mimeFromUrl( $url );
		$mimeExtension = (string)( MediaWikiServices::getInstance()
			->getMimeAnalyzer()->getExtensionFromMimeTypeOrNull( $mime ) ?? '' );
		$destName = self::destName(
			$primaryLabel( $record ),
			$prefix,
			$name,
			$mimeExtension,
			self::IMAGE_EXTENSIONS
		);
		if ( $destName === '' ) {
			return null;
		}
		$base = new UploadFromUrl();
		$base->initialize( $destName, $url );
		// initialize() only creates the EMPTY temp file — the download
		// happens in fetchFile() (streams the body, SSRF-validating each
		// redirect hop); without it verifyUpload() rejects EMPTY_FILE (3).
		$status = $base->fetchFile();
		$tempPath = $base->getTempPath();
		if ( !$status->isGood() || $tempPath === '' || !is_file( $tempPath ) ) {
			return null;
		}
		return self::performUpload( $prefix, $base, $record, $context, $user, $msgKeys, $primaryLabel );
	}

	/** verifyUpload + performUpload; a rejected upload throws. */
	private static function performUpload(
		string $prefix,
		UploadBase $base,
		array $record,
		IContextSource $context,
		User $user,
		array $msgKeys,
		callable $primaryLabel
	): ?Title {
		$title = $base->getTitle();
		if ( $title === null ) {
			return null;
		}
		if ( $title->exists() ) {
			return $title; // idempotent: already uploaded on an earlier run
		}
		$verify = $base->verifyUpload();
		if ( ( $verify['status'] ?? null ) !== UploadBase::OK ) {
			$details = $verify['details'] ?? [];
			$detail = is_array( $details ) && $details !== []
				? (string)( $details[0] ?? '' )
				: (string)( $verify['status'] ?? 'rejected' );
			throw new \RuntimeException( 'verifyUpload rejected (' . $detail . ')' );
		}
		$label = $primaryLabel( $record );
		$sourceUrl = trim( (string)$context->getRequest()->getVal( 'wbUploadmetaSourceUrl', '' ) );
		$pageText = self::pageText( $label, $prefix, $sourceUrl );
		$status = $base->performUpload(
			$context->msg( $msgKeys['editSummary'], $label )->inContentLanguage()->text(),
			$pageText,
			false,
			$user
		);
		if ( !$status->isOK() ) {
			throw new \RuntimeException( 'performUpload: ' . ( $status->getMessage()->getParams()[0] ?? 'rejected' ) );
		}
		return $title;
	}

	/** Best-effort remote MIME probe (HEAD); '' when the probe fails. */
	private static function mimeFromUrl( string $url ): string {
		try {
			$http = MediaWikiServices::getInstance()->getHttpRequestFactory()
				->create( $url, [], __METHOD__ );
			$http->execute();
			return (string)$http->getResponseHeader( 'Content-Type' );
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * Resolves the mode=existing combobox value ("File:<name>" or a bare
	 * name) to an EXISTING File: page title, or null when the file does not
	 * exist on this wiki (the caller surfaces it as a form error — a
	 * provided-but-invalid reuse is never silently skipped). No upload
	 * happens; the image/license statements reference the existing page.
	 */
	private static function reuseExistingFile( string $prefix, array $record ): ?Title {
		$name = trim( (string)( $record[$prefix . 'Existing'] ?? '' ) );
		$name = (string)preg_replace( '/^File\s*:/i', '', $name );
		$name = trim( (string)str_replace( '_', ' ', $name ) );
		if ( $name === '' ) {
			return null;
		}
		try {
			$title = Title::makeTitle( NS_FILE, $name );
		} catch ( Throwable $e ) {
			return null;
		}
		return ( $title !== null && $title->exists() ) ? $title : null;
	}

	// ------------------------------------------------------------- pure helpers

	/**
	 * Destination file name "<label>-<suffix>.<ext>" (portrait/logo): the
	 * extension comes from the original name, restricted to $allowed, with
	 * a fallback to the MIME type's canonical extension (resolved by the
	 * caller — keeps this method MW-free and unit-testable). Returns ''
	 * when the format is unsupported or the label is unusable.
	 *
	 * @param string[] $allowed allowed extensions (lowercase, no dot)
	 * @param string|null $mimeExtension canonical extension of the MIME type, or null
	 */
	public static function destName(
		string $label,
		string $suffix,
		string $originalName,
		?string $mimeExtension,
		array $allowed
	): string {
		$ext = strtolower( (string)pathinfo( $originalName, PATHINFO_EXTENSION ) );
		if ( !in_array( $ext, $allowed, true ) ) {
			$ext = strtolower( (string)( $mimeExtension ?? '' ) );
		}
		if ( !in_array( $ext, $allowed, true ) ) {
			return '';
		}
		$clean = (string)preg_replace( '/[#<>\[\]|{}:]/', '', trim( $label ) );
		$clean = trim( (string)preg_replace( '/\s+/', ' ', $clean ) );
		if ( $clean === '' ) {
			return '';
		}
		return "{$clean}-{$suffix}.{$ext}";
	}

	/**
	 * File description page text: the identifying sentence (per-page: the
	 * portrait/logo is uploaded from Special:AddPerson/AddSoftware) + the
	 * original source URL when the browser-blob path carried one
	 * (provenance).
	 */
	public static function pageText( string $label, string $suffix, string $sourceUrl = '' ): string {
		$text = ucfirst( $suffix ) . " of {$label}.";
		if ( $sourceUrl !== '' ) {
			$text .= "\n\nSource: {$sourceUrl}";
		}
		return $text;
	}

	/**
	 * Parses a single item id string ("Q42") into an ItemId, or null when
	 * invalid (the license combobox submits item ids; a typed label is
	 * rejected and surfaced as a form error).
	 */
	private static function parseItemId( string $input ): ?\Wikibase\DataModel\Entity\ItemId {
		$input = trim( $input );
		if ( $input === '' ) {
			return null;
		}
		try {
			$id = \Wikibase\Repo\WikibaseRepo::getEntityIdParser()->parse( $input );
			return $id instanceof \Wikibase\DataModel\Entity\ItemId ? $id : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}
}
