<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Upload;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Upload\UploadBase;
use Wikibase\Repo\WikibaseRepo;

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
		// OOUIComboboxField forces the OOUI ComboBoxInputWidget (infusable)
		// so the entity-suggest module wires it exactly like the Add* pages'.
		$descriptor['License'] = [
			'class' => \EmbeddableContent\Upload\OOUIComboboxField::class,
			'options' => self::config()->licenseItems(),
			'section' => 'description',
			'id' => 'wpLicense',
			'label-message' => 'embeddablecontent-upload-license',
			'cssclass' => 'wb-entity-combobox',
			// MW 1.46: 'help' is raw HTML (deprecated); 'help-message' is the
			// key form — the bare key string rendered verbatim before.
			'help-message' => 'embeddablecontent-upload-license-help',
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
		// The core builds the radios with 'checked' from the posted (or
		// default 'File') source type BEFORE this hook runs. A fresh GET
		// carries no wpSourceType — flip the default to Url then; a POST
		// (including a warning-recovery re-render) is honored as-is.
		if ( !RequestContext::getMain()->getRequest()->getCheck( 'wpSourceType' ) ) {
			$descriptor['UploadFile']['checked'] = false;
			if ( isset( $descriptor['UploadFileURL'] ) ) {
				$descriptor['UploadFileURL']['checked'] = true;
			}
		}
		if ( isset( $descriptor['UploadFile'] ) ) {
			$descriptor['UploadFile']['help-raw'] = wfMessage( 'upload-maxfilesize' )
				->sizeParams( UploadBase::getMaxUploadSize( 'file' ) )
				->parse();
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
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new \Wikibase\DataModel\Entity\ItemId( $itemId ) );
			if ( $entity instanceof \Wikibase\DataModel\Entity\Item ) {
				$term = $entity->getLabels()->getByLanguage( 'en' );
				if ( $term !== null ) {
					return $term->getText();
				}
			}
		} catch ( \Throwable $e ) {
			// fall through
		}
		return $itemId;
	}

	private static function config(): EmbeddableContentConfig {
		return MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' );
	}
}
