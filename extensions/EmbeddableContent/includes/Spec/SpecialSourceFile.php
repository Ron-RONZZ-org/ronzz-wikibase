<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * Special:SourceFile — the download-gated file page for copies stored on
 * the instance (issue follow-up, ADR docs/decisions/source-access-rendering.md).
 *
 * Reachable from the Source: infobox's "Access" row (the `{{#source-access:}}`
 * parser function links the stored copy here with the owning item id):
 *
 *   Special:SourceFile?item=Q42&file=File:Foo.pdf
 *
 * Renders:
 *   * an embedded PDF preview (self-hosted iframe — no PdfHandler needed;
 *     images get a thumbnail, other types a file-page link),
 *   * the licence information (the owning item's `license` statement, linked
 *     to the licence item — plus its URL when one is recorded),
 *   * a download button gated on a checkbox accepting the licence conditions
 *     (server-side; unchecked = form error, checked = redirect to the file).
 *
 * Public copies: the page LOAD is not login-gated (preview + licence are
 * informational); the download SUBMIT enforces login (house pattern — the
 * instance is anon read-only). Internal-use copies: the page LOAD keeps the
 * title + licence ("file info") public, but the preview and the download form
 * are replaced by the copyright/login message for anonymous readers (the
 * media itself is gated by nginx; see Spec\InternalFile).
 *
 * @license GPL-2.0-or-later
 */
class SpecialSourceFile extends SpecialPage {

	/** @var EmbeddableContentConfig */
	private $config;

	public function __construct( EmbeddableContentConfig $config ) {
		parent::__construct( 'SourceFile' );
		$this->config = $config;
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->getOutput()->enableOOUI();

		$request = $this->getRequest();
		$fileParam = trim( (string)( $request->getVal( 'file' ) ?: $subPage ) );
		$itemParam = trim( (string)$request->getVal( 'item' ) );

		$file = $this->resolveFile( $fileParam );
		if ( $file === null ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-sourcefile-invalidfile', $fileParam ?: '?' )->escaped() )
			);
			return;
		}

		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-sourcefile-title', $file->getTitle()->getText() )->text() );

		$internal = InternalFile::isInternal( $file->getTitle() );

		// Licence information from the owning item's `license` statement.
		$itemId = $this->parseItemId( $itemParam );
		$license = $itemId !== null
			? $this->licenseInfo( $itemId, $file->getTitle()->getPrefixedText() )
			: null;

		// Internal-use copy: the page itself (title + licence = "file
		// info") stays public; the media and the download are login-gated.
		if ( $internal && $this->getUser()->isAnon() ) {
			$this->getOutput()->addHTML( $this->licenceHtml( $license ) );
			$this->getOutput()->addHTML( $this->internalLoginNoticeHtml() );
			$this->getOutput()->addHTML( $this->filePageLinkHtml( $file ) );
			return;
		}

		// 1. Embedded preview: PDFs render in a self-hosted iframe (the
		// browser's native viewer); images get a thumbnail; other types get
		// a file-page link (no blank surface). The logged-in media cookie
		// lets nginx serve the internal file's preview.
		$this->getOutput()->addHTML( $this->previewHtml( $file ) );

		// 2. Licence information.
		$this->getOutput()->addHTML( $this->licenceHtml( $license ) );

		// 3. Download form. Public files keep the licence-acceptance
		// checkbox; internal files are gated by login alone (the licence is
		// recorded automatically as "All rights reserved").
		if ( $internal ) {
			$fields = [
				'internal' => [
					'type' => 'hidden',
					'default' => '1',
				],
			];
		} else {
			$acceptMessage = $license !== null
				? $this->msg( 'embeddablecontent-sourcefile-accept', $license['label'] )
				: $this->msg( 'embeddablecontent-sourcefile-accept-unknown' );
			$fields = [
				'accept' => [
					'type' => 'check',
					'label-message' => $acceptMessage,
					'required' => true,
				],
			];
		}
		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$form->setTitle( $this->getPageTitle() )
			->setAction( $this->getPageTitle()->getFullURL( [ 'file' => $fileParam, 'item' => $itemParam ] ) )
			->setSubmitTextMsg( 'embeddablecontent-sourcefile-download' )
			->setSubmitID( 'wb-sourcefile-download' )
			->setSubmitCallback( [ $this, 'onDownloadSubmit' ] )
			->setWrapperLegendMsg( 'embeddablecontent-sourcefile-download-legend' );
		$form->show();

		$this->getOutput()->addHTML( $this->filePageLinkHtml( $file ) );
	}

	/**
	 * The copyright/login notice for an internal-use file, with the login
	 * link returning to this page (title + query).
	 */
	private function internalLoginNoticeHtml(): string {
		$this->getOutput()->addModuleStyles( [
			'mediawiki.codex.messagebox.styles',
			'ext.embeddableContent.internalfile',
		] );
		$login = SpecialPage::getTitleFor( 'UserLogin' )->getFullURL( [
			'returnto' => $this->getPageTitle()->getPrefixedText(),
			'returntoquery' => $this->getRequest()->getRawQueryString(),
		] );
		$link = Html::element( 'a', [ 'href' => $login ], $this->msg( 'login' )->text() );
		return Html::warningBox(
			htmlspecialchars( $this->msg( 'embeddablecontent-internal-file-loginrequired' )->text() )
				. ' ' . $link,
			'wb-internal-file-placeholder'
		);
	}

	/** @param \MediaWiki\FileRepo\File\File $file */
	private function filePageLinkHtml( \MediaWiki\FileRepo\File\File $file ): string {
		return '<p><a href="' . htmlspecialchars( $file->getTitle()->getLocalURL() ) . '">'
			. $this->msg( 'embeddablecontent-sourcefile-filepage' )->escaped() . '</a></p>';
	}

	/**
	 * Download submit: login + licence-acceptance gate. A checked box is
	 * validated by HTMLForm (required); the server-side re-check protects
	 * against crafted requests. On success the user is redirected to the
	 * file itself.
	 *
	 * @param array<string,mixed> $data
	 * @return bool|string
	 */
	public function onDownloadSubmit( array $data ) {
		$loginError = $this->loginRequiredError();
		if ( $loginError !== null ) {
			return $loginError;
		}
		$file = $this->resolveFile( (string)$this->getRequest()->getVal( 'file' ) );
		if ( $file === null ) {
			return $this->msg( 'embeddablecontent-sourcefile-invalidfile' )->text();
		}
		// Internal-use copies are gated by login alone; public copies keep
		// the licence-acceptance gate. Decided from the FILE, not the
		// submitted field (a crafted field must not skip the licence).
		if ( !InternalFile::isInternal( $file->getTitle() ) && empty( $data['accept'] ) ) {
			return $this->msg( 'embeddablecontent-sourcefile-accept-required' )->text();
		}
		$this->getOutput()->redirect( $file->getUrl() );
		return true;
	}

	// ------------------------------------------------------------- preview

	/**
	 * @param \MediaWiki\FileRepo\File\File $file resolved upload
	 * @return string HTML for the preview block
	 */
	private function previewHtml( \MediaWiki\FileRepo\File\File $file ): string {
		$mime = (string)$file->getMimeType();
		$ext = strtolower( (string)$file->getExtension() );
		if ( $mime === 'application/pdf' || $ext === 'pdf' ) {
			// NO sandbox attribute: Chrome's PDF viewer does not initialize
			// in a sandboxed frame (the iframe shows chrome-error://…, a
			// blank preview). The frame is same-origin and the content is
			// MIME-locked to application/pdf at upload (no HTML/script can
			// be served through it); PDF-embedded JavaScript, when present,
			// runs inside PDFium's own isolated sandbox regardless.
			$this->getOutput()->addModules( 'ext.embeddableContent.pdfviewer' );
			return Html::rawElement( 'div', [ 'class' => 'wb-sourcefile-preview wb-pdf-viewer' ],
				Html::rawElement( 'div', [ 'class' => 'wb-pdf-toolbar' ] )
				. Html::rawElement( 'iframe', [
					'src' => $file->getUrl(),
					'width' => '100%',
					'height' => '640',
					'title' => $this->msg( 'embeddablecontent-sourcefile-preview' )->text(),
				] )
			);
		}
		$mediaType = $file->getMediaType();
		if ( $mediaType === MEDIATYPE_BITMAP || $mediaType === MEDIATYPE_DRAWING ) {
			$thumb = $file->transform( [ 'width' => 320 ] );
			if ( $thumb !== false && $thumb->getUrl() !== false ) {
				return Html::rawElement( 'div', [ 'class' => 'wb-sourcefile-preview' ],
					Html::rawElement( 'img', [
						'src' => $thumb->getUrl(),
						'width' => (string)$thumb->getWidth(),
						'alt' => $file->getTitle()->getText(),
					] )
				);
			}
		}
		// Audio/video/text: no inline preview — the file page has the player.
		return '';
	}

	// ------------------------------------------------------------- licence

	/**
	 * Licence facts of the owning item: the matching access file's licence
	 * qualifier (multi-access), else the legacy top-level `license` statement
	 * (P275-aligned, entity) → the licence item's label (+ URL when recorded).
	 * null when no licence is recorded (defensive) or the item is unresolvable.
	 *
	 * @return array{label:string,url:string|null,itemId:string}|null
	 */
	private function licenseInfo( ItemId $itemId, ?string $fileTitle = null ): ?array {
		$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		if ( !$entity instanceof Item ) {
			return null;
		}
		$props = $this->config->sourcePropertyIds();
		$licenseId = null;
		if ( $fileTitle !== null ) {
			foreach ( \EmbeddableContent\Spec\AccessEntries::fromItem( $entity, $this->config ) as $entry ) {
				if ( $entry['type'] === \EmbeddableContent\Spec\AccessEntries::TYPE_FILE
					&& $entry['fileTitle'] === $fileTitle
					&& !empty( $entry['license'] )
				) {
					$licenseId = new ItemId( $entry['license'] );
					break;
				}
			}
		}
		if ( $licenseId === null ) {
			$licensePropId = $props['license'] ?? null;
			if ( $licensePropId === null ) {
				return null;
			}
			try {
				// PropertyId is an interface in the DataModel — NumericPropertyId is the concrete class.
				$propertyId = new NumericPropertyId( $licensePropId );
			} catch ( \Throwable $e ) {
				return null;
			}
			foreach ( $entity->getStatements()->getByPropertyId( $propertyId ) as $statement ) {
				$snak = $statement->getMainSnak();
				if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof EntityIdValue ) {
					$candidate = $snak->getDataValue()->getEntityId();
					if ( $candidate instanceof ItemId ) {
						$licenseId = $candidate;
						break;
					}
				}
			}
		}
		if ( $licenseId === null ) {
			return null;
		}
		$licenseEntity = WikibaseRepo::getEntityLookup()->getEntity( $licenseId );
		if ( !$licenseEntity instanceof Item ) {
			return null;
		}
		$label = $this->itemLabel( $licenseEntity );
		if ( $label === null ) {
			return null;
		}
		return [
			'label' => $label,
			'url' => $this->licenseUrl( $licenseEntity, $props ),
			'itemId' => $licenseId->getSerialization(),
		];
	}

	/** URL of the licence item's `url` statement (the licence text), or null. */
	private function licenseUrl( Item $licenseEntity, array $props ): ?string {
		$urlPropId = $props['url'] ?? null;
		if ( $urlPropId === null ) {
			return null;
		}
		try {
			$propertyId = new NumericPropertyId( $urlPropId );
		} catch ( \Throwable $e ) {
			return null;
		}
		foreach ( $licenseEntity->getStatements()->getByPropertyId( $propertyId ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof StringValue ) {
				return $snak->getDataValue()->getValue();
			}
		}
		return null;
	}

	/** Label of an item in the reader's language (fallback: en, then any). */
	private function itemLabel( Item $item ): ?string {
		return EntityLabelText::of( $item, $this->getLanguage()->getCode() );
	}

	/**
	 * Licence block HTML: label linked to the licence item (+ external URL).
	 * Unknown when the item carries no licence statement.
	 *
	 * @param array{label:string,url:string|null,itemId:string}|null $license
	 */
	private function licenceHtml( ?array $license ): string {
		if ( $license === null ) {
			return Html::rawElement( 'p', [ 'class' => 'wb-sourcefile-licence' ],
				$this->msg( 'embeddablecontent-sourcefile-licence' )->escaped()
				. ': ' . $this->msg( 'embeddablecontent-sourcefile-licence-unknown' )->escaped()
			);
		}
		$itemUrl = WikibaseRepo::getEntityTitleStoreLookup()->getTitleForId( new ItemId( $license['itemId'] ) )->getFullURL();
		$label = '<a href="' . htmlspecialchars( $itemUrl ) . '">' . htmlspecialchars( $license['label'] ) . '</a>';
		if ( $license['url'] !== null ) {
			$label .= ' (<a href="' . htmlspecialchars( $license['url'] ) . '">'
				. $this->msg( 'embeddablecontent-sourcefile-licence-text' )->escaped() . '</a>)';
		}
		return Html::rawElement( 'p', [ 'class' => 'wb-sourcefile-licence' ],
			$this->msg( 'embeddablecontent-sourcefile-licence' )->escaped() . ': ' . $label
		);
	}

	// ------------------------------------------------------------- shared

	/**
	 * Resolves the `file` parameter to an existing local upload, or null.
	 * Accepts a full or bare File: title; the namespace is enforced.
	 */
	private function resolveFile( string $fileParam ): ?\MediaWiki\FileRepo\File\File {
		$fileParam = trim( $fileParam );
		if ( $fileParam === '' ) {
			return null;
		}
		$title = Title::newFromText( $fileParam );
		if ( $title === null || !$title->inNamespace( NS_FILE ) ) {
			return null;
		}
		$file = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $title );
		return ( $file !== false && $file->exists() ) ? $file : null;
	}

	/** @return ItemId|null valid Q-id from the `item` parameter, or null */
	private function parseItemId( string $input ): ?ItemId {
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

	private function loginRequiredError(): ?string {
		return $this->getUser()->isAnon()
			? $this->msg( 'embeddablecontent-sourcefile-loginrequired' )->text()
			: null;
	}
}
