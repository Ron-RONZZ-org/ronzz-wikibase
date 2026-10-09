<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Upload;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Fields\EntityCombobox;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Upload\UploadBase;

/**
 * Special:Upload enhancements (the upload-enhancements batch):
 *
 *  - semantic license combobox replacing the core MediaWiki:Licenses
 *    dropdown (UploadFormInitDescriptor) — the value is a license ITEM id,
 *    so the file page gets a wikilink reference instead of a {{template}};
 *  - free-text "image author" + "additional license information" fields;
 *  - the duplicated "Maximum file size: 1 GB (your chosen file from your
 *    device)" notes collapse to a single note on the file field
 *    (UploadFormSourceDescriptors), and the URL field gains the shared
 *    validate-button wiring span (uploadmeta.js);
 *  - the file description page text carries the human-readable attribution
 *    (UploadForm:getInitialPageText);
 *  - item-per-upload (UploadComplete, marker-gated): every Special:Upload
 *    form submission creates/reuses the sitelinked image item holding the
 *    semantic statements (ImageItemCreator) — MsUpload drag-drop and API
 *    uploads are untouched.
 *
 * Hook handler methods are static (the extension.json "upload" handler);
 * MediaWiki passes the descriptor / pageText by reference.
 *
 * @license GPL-2.0-or-later
 */
final class UploadHooks {

	/**
	 * UploadFormInitDescriptor: replace the core License dropdown with the
	 * semantic entity combobox (options from the seed's license items +
	 * entity search), add the author + additional-license-info text fields,
	 * and mark the form for itemization on submit.
	 *
	 * @param array<string,mixed> $descriptor
	 */
	public static function onUploadFormInitDescriptor( array &$descriptor ): void {
		// The UploadForm is a php-mode HTMLForm — a plain `combobox` type
		// would render as an <input>+<datalist> with no entity autocomplete.
		// The shared Fields\EntityCombobox builder sets OOUIComboboxField
		// (forces the OOUI ComboBoxInputWidget, infusable) so the
		// entity-suggest module wires it exactly like the Add* pages', and
		// scopes the search to the license class.
		$descriptor['License'] = EntityCombobox::licenseSpec(
			'embeddablecontent-upload-license',
			'embeddablecontent-upload-license-help',
			self::config()
		) + [
			'section' => 'description',
			'id' => 'wpLicense',
		];
		$descriptor['UploadAuthor'] = [
			'type' => 'text',
			'section' => 'description',
			'id' => 'wpUploadAuthor',
			'label-message' => 'embeddablecontent-upload-author',
			'maxlength' => 250,
		];
		$descriptor['UploadLicenseInfo'] = [
			'type' => 'text',
			'section' => 'description',
			'id' => 'wpUploadLicenseInfo',
			'label-message' => 'embeddablecontent-upload-licenseinfo',
			'maxlength' => 250,
		];
		// Marker: only Special:Upload FORM submissions get the image item
		// (MsUpload drag-drop and API uploads carry no wpUploadmetaItemize).
		$descriptor['UploadmetaItemize'] = [
			'type' => 'hidden',
			'id' => 'wpUploadmetaItemize',
			'default' => '1',
		];
		// Image-processing options (resources/uploadimage.js): resize large
		// images before upload, and auto-correct the file extension from the
		// file's detected MIME type. Both default ON. The field NAMES match
		// the module's discovery contract (`wpUploadResize` /
		// `wpUploadAutoExt`, derived from the file field `wpUploadFile`).
		$descriptor['UploadResize'] = [
			'type' => 'check',
			'section' => 'options',
			'id' => 'wpUploadResize',
			'label-message' => 'embeddablecontent-upload-resize',
			'default' => true,
		];
		$descriptor['UploadAutoExt'] = [
			'type' => 'check',
			'section' => 'options',
			'id' => 'wpUploadAutoExt',
			'label-message' => 'embeddablecontent-upload-autoext',
			'default' => true,
		];
		// "Copy internal embed code" (2026-09 UX batch): checked by default;
		// on a successful upload the destination File: page copies
		// [[File:xxx]] to the clipboard (BeforePageRedirect appends
		// ?wbuploadcopy=1; resources/filepage.js performs the copy with the
		// FINAL file name).
		$descriptor['UploadCopyEmbed'] = [
			'type' => 'check',
			'section' => 'options',
			'id' => 'wpUploadCopyEmbed',
			'label-message' => 'embeddablecontent-upload-copyembed',
			'default' => true,
		];
		// "Submit and upload another image from same author": a SECOND submit
		// button. Core only processes an upload when wpUpload is checked
		// (SpecialUpload::loadRequest), so it must carry name=wpUpload; the
		// value 'another' distinguishes the click. BeforePageRedirect reads
		// it and appends ?wbanother=1 + the preserved fields; the new-tab +
		// prefilled-reload behaviour is wired by resources/uploadform.js.
		$descriptor['UploadAnother'] = [
			'type' => 'submit',
			'name' => 'wpUpload',
			'default' => 'another',
			'buttonlabel-message' => 'embeddablecontent-upload-another',
			'id' => 'wpUploadAnother',
			'flags' => [],
		];
	}

	/**
	 * UploadFormSourceDescriptors: single "Maximum file size: 1 GB" note on
	 * the file field (the duplicated parentheticals are gone); the URL
	 * field's note slot carries the validate-button wiring span instead.
	 * Also defaults the source radio to Url on a FRESH form load — URL
	 * uploads are the common case in user testing (a posted/resubmitted
	 * form keeps its own wpSourceType).
	 *
	 * @param array<string,mixed> $descriptor
	 */
	public static function onUploadFormSourceDescriptors( array &$descriptor, &$radio, $selectedSourceType ): void {
		$request = RequestContext::getMain()->getRequest();
		// The browser blob fallback converts a Wikimedia URL upload into a
		// file upload (mode switched to 'File') for the internal resubmit and
		// records the user's ORIGINAL source selection in
		// wbUploadmetaSourceType. An error re-render must show that selection,
		// not the internal conversion — otherwise the MIME-mismatch error
		// resets the radio to "Source filename".
		$convertedFrom = trim( (string)$request->getVal( 'wbUploadmetaSourceType', '' ) );
		if ( $convertedFrom !== '' ) {
			$isUrl = strtolower( $convertedFrom ) === 'url';
			$descriptor['UploadFile']['checked'] = !$isUrl;
			if ( isset( $descriptor['UploadFileURL'] ) ) {
				$descriptor['UploadFileURL']['checked'] = $isUrl;
			}
		} elseif ( ( $remembered = strtolower( trim( (string)$request->getVal( 'wbsourcetype', '' ) ) ) ) !== '' ) {
			// "Upload another image from same author" hand-off: the source
			// radio the user picked on the previous upload is restored
			// (BeforePageRedirect appends wbsourcetype; filepage.js carries
			// it onto the reloaded form).
			$isUrl = $remembered === 'url';
			$descriptor['UploadFile']['checked'] = !$isUrl;
			if ( isset( $descriptor['UploadFileURL'] ) ) {
				$descriptor['UploadFileURL']['checked'] = $isUrl;
			}
		} elseif ( !$request->getCheck( 'wpSourceType' ) ) {
			// The core builds the radios with 'checked' from the posted (or
			// default 'File') source type BEFORE this hook runs. A fresh GET
			// carries no wpSourceType — flip the default to Url then; a POST
			// (including a warning-recovery re-render) is honored as-is.
			$descriptor['UploadFile']['checked'] = false;
			if ( isset( $descriptor['UploadFileURL'] ) ) {
				$descriptor['UploadFileURL']['checked'] = true;
			}
		}
		if ( isset( $descriptor['UploadFile'] ) ) {
			$descriptor['UploadFile']['help-raw'] = wfMessage( 'upload-maxfilesize' )
				->sizeParams( UploadBase::getMaxUploadSize( 'file' ) )
				->parse()
				// resources/uploadform.js makes this field a drop target and
				// accepts a clipboard image (the "drag an image here or
				// paste it" hint).
				. '<div class="wb-upload-drop-hint">'
				. wfMessage( 'embeddablecontent-upload-drop-hint' )->parse()
				. '</div>'
				// resources/uploadimage.js fills this with the selected
				// local file's preview (thumbnail + pixel/byte size).
				. '<div class="wb-image-preview wb-uploadmeta-preview"></div>';
		}
		if ( isset( $descriptor['UploadFileURL'] ) ) {
			// The size limit is identical for both source types and is shown
			// once on the file field — the URL field gets the validate button
			// + preview area, plus its own URL cap note (the browser-blob
			// path and UploadFromUrl both honour $wgMaxUploadSize['url']).
			$descriptor['UploadFileURL']['help-raw'] = self::uploadmetaSpan()
				. '<div class="wb-uploadmeta-size-note">'
				. wfMessage( 'embeddablecontent-upload-url-maxsize' )
					->sizeParams( UploadBase::getMaxUploadSize( 'url' ) )
					->parse()
				. '</div>';
		}
	}

	/**
	 * UploadForm:BeforeProcessing: the server-side half of the
	 * "auto-correct the file extension" option. `wpDestFile` may carry a
	 * missing or wrong extension (typed by the user, or derived from the
	 * source filename); when `wpUploadAutoExt` is set, relabel the
	 * destination to the extension of the file's DETECTED MIME type before
	 * verification — a PNG saved as ".jpg" then stores as ".png" instead of
	 * drawing a `filetype-mime-mismatch` error. resources/uploadimage.js
	 * does the same eagerly in the browser; this net also covers JS-off and
	 * non-interactive resubmits.
	 *
	 * Hook handler name: `UploadForm:BeforeProcessing` →
	 * onUploadForm_BeforeProcessing (the colon is kept, like the sibling
	 * getInitialPageText handler).
	 *
	 * @param object $specialUpload the SpecialUpload instance (public
	 *  mUpload / mDesiredDestName are read and the destination is renamed)
	 */
	public static function onUploadForm_BeforeProcessing( $specialUpload ): bool {
		$request = RequestContext::getMain()->getRequest();
		if ( !$request->getCheck( 'wpUploadAutoExt' ) ) {
			return true;
		}
		if ( !is_object( $specialUpload )
			|| !isset( $specialUpload->mUpload )
			|| !$specialUpload->mUpload instanceof UploadBase
		) {
			return true;
		}
		$upload = $specialUpload->mUpload;
		$tempPath = (string)$upload->getTempPath();
		$destName = (string)$upload->getDesiredDestName();
		if ( $tempPath === '' || $destName === '' || !is_file( $tempPath ) ) {
			return true;
		}
		try {
			$mime = MediaWikiServices::getInstance()->getMimeAnalyzer()->guessMimeType( $tempPath, false );
			$ext = \EmbeddableContent\Fetch\CommonsMetadataParser::extensionForMime( (string)$mime );
		} catch ( \Throwable $e ) {
			// Non-fatal: keep the submitted name, verification decides.
			wfLogWarning( 'EmbeddableContent: auto-correct extension probe failed: ' . $e->getMessage() );
			return true;
		}
		if ( $ext === '' ) {
			return true;
		}
		if ( strtolower( (string)pathinfo( $destName, PATHINFO_EXTENSION ) ) === $ext ) {
			return true;
		}
		$dot = strrpos( $destName, '.' );
		$base = ( $dot !== false && $dot > 0 ) ? substr( $destName, 0, $dot ) : $destName;
		$newName = $base . '.' . $ext;
		try {
			$upload->initializePathInfo( $newName, $tempPath, $upload->getFileSize() );
			$specialUpload->mDesiredDestName = $newName;
		} catch ( \Throwable $e ) {
			wfLogWarning( 'EmbeddableContent: auto-correct extension rename failed: ' . $e->getMessage() );
		}
		return true;
	}

	/**
	 * UploadForm:getInitialPageText: the license combobox value is an ITEM
	 * id — the core would have rendered it as a {{Q42}} template call.
	 * Replace the license section with a semantic reference (wikilink +
	 * label) and append the attribution block (author / license info /
	 * source) when provided.
	 *
	 * Hook handler name: MediaWiki maps `UploadForm:getInitialPageText` to
	 * onUploadForm_getInitialPageText (colons become underscores).
	 *
	 * @param string $pageText
	 * @param array<string,string> $msg content-language header messages
	 * @param \MediaWiki\Config\Config $config
	 */
	public static function onUploadForm_getInitialPageText( &$pageText, $msg, $config ): void {
		$request = RequestContext::getMain()->getRequest();
		$license = trim( (string)$request->getVal( 'wpLicense', '' ) );
		$author = trim( (string)$request->getVal( 'wpUploadAuthor', '' ) );
		$licenseInfo = trim( (string)$request->getVal( 'wpUploadLicenseInfo', '' ) );
		$sourceUrl = trim( (string)$request->getVal( 'wbUploadmetaSourceUrl', '' ) );
		if ( $sourceUrl === '' ) {
			$sourceUrl = trim( (string)$request->getVal( 'wpUploadFileURL', '' ) );
		}

		$licenseHeader = (string)( $msg['license-header'] ?? 'License' );

		// Strip the core's "== License ==\n{{Q42}}" block (the combobox
		// value must never render as a template call), then re-add it as a
		// semantic reference when the value is a valid item id.
		$pageText = (string)preg_replace(
			'/==\s*' . preg_quote( $licenseHeader, '/' ) . '\s*==\s*\{\{[^}]*\}\}\s*/iu',
			'',
			$pageText
		);
		if ( preg_match( '/^Q[1-9]\d*$/i', $license ) === 1 ) {
			$label = self::licenseLabel( $license );
			$pageText .= '== ' . $licenseHeader . " ==\n"
				. '[[' . $license . '|' . htmlspecialchars( $label ) . "]]\n";
		}

		if ( $author !== '' || $licenseInfo !== '' || $sourceUrl !== '' ) {
			$lines = [];
			if ( $author !== '' ) {
				$lines[] = wfMessage( 'embeddablecontent-upload-attribution-author' )->inContentLanguage()->text()
					. ': ' . $author;
			}
			if ( $licenseInfo !== '' ) {
				$lines[] = wfMessage( 'embeddablecontent-upload-attribution-licenseinfo' )->inContentLanguage()->text()
					. ': ' . $licenseInfo;
			}
			if ( $sourceUrl !== '' ) {
				$lines[] = wfMessage( 'embeddablecontent-upload-attribution-source' )->inContentLanguage()->text()
					. ': ' . $sourceUrl;
			}
			if ( $lines !== [] ) {
				$pageText .= '== '
					. wfMessage( 'embeddablecontent-upload-attribution-header' )->inContentLanguage()->text()
					. " ==\n" . implode( "\n", $lines ) . "\n";
			}
		}
	}

	/**
	 * UploadComplete: item-per-upload for Special:Upload form submissions
	 * (marker-gated — MsUpload/API uploads are untouched). Creates/reuses
	 * the sitelinked image item with the semantic statements.
	 */
	public static function onUploadComplete( UploadBase $uploadBase ): void {
		if ( RequestContext::getMain()->getRequest()->getVal( 'wpUploadmetaItemize' ) !== '1' ) {
			return;
		}
		// The uploader is the request user (UploadFromFile has no getUser()).
		$user = RequestContext::getMain()->getUser();
		if ( !$user instanceof \MediaWiki\User\User || !$user->isRegistered() ) {
			return;
		}
		$title = $uploadBase->getTitle();
		// NOTE: no Title::exists() check here — at UploadComplete time the
		// file row is being written in the same transaction and the title's
		// existence cache still holds the pre-upload negative; the sitelink
		// and the image statement work on the page NAME regardless.
		if ( $title === null || $title->getNamespace() !== NS_FILE ) {
			return;
		}
		$request = RequestContext::getMain()->getRequest();
		$label = (string)preg_replace( '/\.[^.]+$/', '', $title->getText() );
		ImageItemCreator::createOrReuse(
			self::config(),
			$user,
			$label,
			trim( (string)$request->getVal( 'wpUploadDescription', '' ) ),
			$title->getPrefixedText(),
			trim( (string)$request->getVal( 'wpLicense', '' ) ) ?: null,
			trim( (string)$request->getVal( 'wpUploadAuthor', '' ) ),
			trim( (string)$request->getVal( 'wpUploadLicenseInfo', '' ) ),
			trim( (string)$request->getVal( 'wbUploadmetaSourceUrl', '' ) ),
			wfMessage( 'embeddablecontent-upload-item-edit-summary', $label )->inContentLanguage()->text()
		);
	}

	/** The validate-button wiring span (uploadmeta.js data-config). */
	private static function uploadmetaSpan(): string {
		$config = [
			'urlField' => 'wpUploadFileURL',
			'fileField' => 'wpUploadFile',
			'modeField' => 'wpSourceType',
			'fileMode' => 'File',
			'licenseLabel' => wfMessage( 'embeddablecontent-upload-license' )->text(),
			'targets' => [
				'name' => 'wpDestFile',
				'description' => 'wpUploadDescription',
				'author' => 'wpUploadAuthor',
				'license' => 'wpLicense',
				'licenseInfo' => 'wpUploadLicenseInfo',
			],
		];
		return '<span class="wb-uploadmeta" data-config="'
			. htmlspecialchars( (string)json_encode( $config ), ENT_QUOTES, 'UTF-8' )
			. '"></span>';
	}

	/** English label of a license item (best-effort). */
	private static function licenseLabel( string $itemId ): string {
		return ImageUploadHelper::licenseLabel( $itemId );
	}

	private static function config(): EmbeddableContentConfig {
		return MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' );
	}
}
