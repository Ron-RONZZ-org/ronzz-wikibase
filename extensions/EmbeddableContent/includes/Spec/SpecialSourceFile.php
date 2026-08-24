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
 * The page LOAD is not login-gated (preview + licence are informational);
 * the download SUBMIT enforces login (house pattern — the instance is
 * anon read-only).
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

		// 1. Embedded preview: PDFs render in a self-hosted iframe (the
		// browser's native viewer); images get a thumbnail; other types get
		// a file-page link (no blank surface).
		$this->getOutput()->addHTML( $this->previewHtml( $file ) );

		// 2. Licence information from the owning item's `license` statement.
		$itemId = $this->parseItemId( $itemParam );
		$license = $itemId !== null ? $this->licenseInfo( $itemId ) : null;
		$this->getOutput()->addHTML( $this->licenceHtml( $license ) );

		// 3. Download form: checkbox accepts the licence conditions.
		$acceptMessage = $license !== null
			? $this->msg( 'embeddablecontent-sourcefile-accept', $license['label'] )
			: $this->msg( 'embeddablecontent-sourcefile-accept-unknown' );
		$form = HTMLForm::factory( 'ooui', [
			'accept' => [
				'type' => 'check',
				'label-message' => $acceptMessage,
				'required' => true,
			],
		], $this->getContext() );
		$form->setTitle( $this->getPageTitle() )
			->setAction( $this->getPageTitle()->getFullURL( [ 'file' => $fileParam, 'item' => $itemParam ] ) )
			->setSubmitTextMsg( 'embeddablecontent-sourcefile-download' )
			->setSubmitID( 'wb-sourcefile-download' )
			->setSubmitCallback( [ $this, 'onDownloadSubmit' ] )
			->setWrapperLegendMsg( 'embeddablecontent-sourcefile-download-legend' );
		$form->show();

		// A link back to the canonical file page (history, other renditions).
		$this->getOutput()->addHTML(
			'<p><a href="' . htmlspecialchars( $file->getTitle()->getLocalURL() ) . '">'
			. $this->msg( 'embeddablecontent-sourcefile-filepage' )->escaped() . '</a></p>'
		);
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
		if ( empty( $data['accept'] ) ) {
			return $this->msg( 'embeddablecontent-sourcefile-accept-required' )->text();
		}
		$file = $this->resolveFile( (string)$this->getRequest()->getVal( 'file' ) );
		if ( $file === null ) {
			return $this->msg( 'embeddablecontent-sourcefile-invalidfile' )->text();
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
			return Html::rawElement( 'div', [ 'class' => 'wb-sourcefile-preview' ],
				Html::rawElement( 'iframe', [
					'src' => $file->getUrl(),
					'width' => '100%',
					'height' => '640',
					'style' => 'border:1px solid #c8ccd1;border-radius:2px;',
					'sandbox' => 'allow-same-origin',
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
	 * Licence facts of the owning item: the `license` statement (P275-aligned,
	 * entity) → the licence item's label (+ its URL statement when recorded).
	 * null when the item has no licence (defensive — the download/file modes
	 * require one at creation) or the item id is unresolvable.
	 *
	 * @return array{label:string,url:string|null,itemId:string}|null
	 */
	private function licenseInfo( ItemId $itemId ): ?array {
		$entity = WikibaseRepo::getEntityLookup()->getEntity( $itemId );
		if ( !$entity instanceof Item ) {
			return null;
		}
		$props = $this->config->sourcePropertyIds();
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
			if ( !$snak instanceof PropertyValueSnak || !$snak->getDataValue() instanceof EntityIdValue ) {
				continue;
			}
			$licenseId = $snak->getDataValue()->getEntityId();
			if ( !$licenseId instanceof ItemId ) {
				continue;
			}
			$licenseEntity = WikibaseRepo::getEntityLookup()->getEntity( $licenseId );
			if ( !$licenseEntity instanceof Item ) {
				continue;
			}
			$label = $this->itemLabel( $licenseEntity );
			if ( $label === null ) {
				continue;
			}
			return [
				'label' => $label,
				'url' => $this->licenseUrl( $licenseEntity, $props ),
				'itemId' => $licenseId->getSerialization(),
			];
		}
		return null;
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
		$language = $this->getLanguage()->getCode();
		$labels = $item->getLabels();
		$term = $labels->getByLanguage( $language ) ?? $labels->getByLanguage( 'en' );
		if ( $term === null ) {
			foreach ( $labels->toTextArray() as $label ) {
				return $label;
			}
			return null;
		}
		return $term->getText();
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
