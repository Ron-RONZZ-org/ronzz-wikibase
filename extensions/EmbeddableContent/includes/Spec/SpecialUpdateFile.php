<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Fields\EntityCombobox;
use EmbeddableContent\Upload\ImageItemCreator;
use EmbeddableContent\Upload\ImageUploadHelper;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * Special:UpdateFile — edit a File: page's semantic image item (license,
 * author, source, license info, source URL, description) and optionally
 * upload a NEW VERSION of the file from a local file or a URL.
 *
 * The File page's Licensing/Attribution block is the `{{#image-facts:}}`
 * parser function (semantic-first): editing the item updates the displayed
 * facts; the old static text is migrated by the backfill script. The
 * new-version upload preserves the page text (description + the derived
 * block).
 *
 * @license GPL-2.0-or-later
 */
class SpecialUpdateFile extends SpecialPage {

	private EmbeddableContentConfig $config;

	public function __construct( EmbeddableContentConfig $config ) {
		parent::__construct( 'UpdateFile' );
		$this->config = $config;
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->getOutput()->enableOOUI();
		$this->getOutput()->addModules( 'ext.embeddableContent.entitysuggest' );
		$this->getOutput()->addModules( 'ext.embeddableContent.uploadmeta' );
		$this->getOutput()->addModules( 'ext.embeddableContent.entityconfirm' );

		$title = $this->resolveFileTitle( $subPage );
		if ( $title === null ) {
			$this->getOutput()->addHTML(
				\MediaWiki\Html\Html::errorBox(
					$this->msg( 'embeddablecontent-updatefile-missing-file' )->escaped()
				)
			);
			return;
		}
		$itemId = $this->sitelinkedItemId( $title );
		$item = $itemId !== null ? $this->loadItem( $itemId ) : null;
		$record = $item instanceof Item ? $this->recordFromItem( $item ) : [];

		$this->getOutput()->setPageTitle(
			$this->msg( 'embeddablecontent-updatefile-title' )->text()
		);
		$form = HTMLForm::factory( 'ooui', $this->fields( $record, $item === null ), $this->getContext() );
		$form->setTitle( $this->getPageTitle( $title->getPrefixedText() ) )
			->setSubmitTextMsg( 'embeddablecontent-updatefile-submit' )
			->setSubmitCallback( fn ( array $data ) => $this->onSubmit( $data, $title, $itemId ) )
			->setSubmitID( 'wb-update-file' )
			->setWrapperLegendMsg( 'embeddablecontent-updatefile-legend' );
		$form->show();
	}

	private function resolveFileTitle( $subPage ): ?Title {
		$raw = is_string( $subPage ) && $subPage !== ''
			? $subPage
			: (string)$this->getRequest()->getVal( 'file', '' );
		$raw = trim( rawurldecode( $raw ) );
		if ( $raw === '' ) {
			return null;
		}
		$title = Title::newFromText( $raw, NS_FILE );
		if ( $title === null || $title->getNamespace() !== NS_FILE || !$title->exists() ) {
			return null;
		}
		return $title;
	}

	private function sitelinkedItemId( Title $title ): ?string {
		try {
			$id = WikibaseRepo::getStore()->newSiteLinkStore()
				->getItemIdForLink( 'wikibase', $title->getPrefixedText() );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $id?->getSerialization();
	}

	private function loadItem( string $itemId ): ?Item {
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $entity instanceof Item ? $entity : null;
	}

	/** @return array<string,mixed> */
	private function recordFromItem( Item $item ): array {
		$props = $this->config->imagePropertyIds();
		$provenance = $this->config->provenancePropertyIds();
		return [
			'description' => $this->itemDescription( $item ),
			'license' => $this->firstEntity( $item, $props['license'] ?? null ),
			'author' => $this->firstString( $item, $props['imageAuthor'] ?? null ),
			'licenseInfo' => $this->firstString( $item, $props['imageLicenseInfo'] ?? null ),
			'sourceText' => $this->firstString( $item, $props['imageSource'] ?? null ),
			'sourceUrl' => $this->firstString( $item, $provenance['sourceUrl'] ?? null ),
			'authorEntity' => implode(
				', ',
				$this->entityIds( $item, $provenance['attributedTo'] ?? null )
			),
			'sourceEntity' => $this->firstEntity( $item, $provenance['source'] ?? null ),
		];
	}

	/** @return array<string,mixed> */
	private function fields( array $record, bool $creating ): array {
		$agentClasses = array_values( $this->config->agentClasses() );
		$sourceClasses = array_values( $this->config->sourceClasses() );
		$fields = [
			'description' => [
				'type' => 'textarea',
				'rows' => 4,
				'label-message' => 'embeddablecontent-updatefile-description',
				'default' => (string)( $record['description'] ?? '' ),
			],
			'license' => EntityCombobox::licenseSpec(
				'embeddablecontent-updatefile-license',
				'embeddablecontent-updatefile-license-help',
				$this->config,
				[ 'default' => (string)( $record['license'] ?? '' ) ]
			),
			'author' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-updatefile-author',
				'maxlength' => 250,
				'default' => (string)( $record['author'] ?? '' ),
			],
			'authorEntity' => EntityCombobox::spec(
				'embeddablecontent-updatefile-authorentity',
				$agentClasses,
				true,
				[ 'default' => (string)( $record['authorEntity'] ?? '' ) ]
			),
			'sourceEntity' => EntityCombobox::spec(
				'embeddablecontent-updatefile-sourceentity',
				$sourceClasses,
				false,
				[ 'default' => (string)( $record['sourceEntity'] ?? '' ) ]
			),
			'sourceText' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-updatefile-sourcetext',
				'maxlength' => 500,
				'default' => (string)( $record['sourceText'] ?? '' ),
			],
			'licenseInfo' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-updatefile-licenseinfo',
				'maxlength' => 250,
				'default' => (string)( $record['licenseInfo'] ?? '' ),
			],
			'sourceUrl' => [
				'type' => 'url',
				'label-message' => 'embeddablecontent-updatefile-sourceurl',
				'maxlength' => 500,
				'default' => (string)( $record['sourceUrl'] ?? '' ),
			],
			'newVersionMode' => [
				'type' => 'radio',
				'label-message' => 'embeddablecontent-updatefile-versionmode',
				'options' => [
					$this->msg( 'embeddablecontent-updatefile-versionmode-none' )->text() => 'none',
					$this->msg( 'embeddablecontent-updatefile-versionmode-file' )->text() => 'file',
					$this->msg( 'embeddablecontent-updatefile-versionmode-url' )->text() => 'url',
				],
				'default' => 'none',
			],
			'newVersionFile' => [
				'type' => 'file',
				'label-message' => 'embeddablecontent-updatefile-versionfile',
				'hide-if' => [ '!==', 'newVersionMode', 'file' ],
			],
			'newVersionUrl' => [
				'type' => 'url',
				'label-message' => 'embeddablecontent-updatefile-versionurl',
				'maxlength' => 500,
				'hide-if' => [ '!==', 'newVersionMode', 'url' ],
			],
			'newVersionComment' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-updatefile-versioncomment',
				'default' => $this->msg( 'embeddablecontent-updatefile-versioncomment-default' )->text(),
				'hide-if' => [ '===', 'newVersionMode', 'none' ],
			],
		];
		if ( $creating ) {
			$fields = [ 'createItem' => [
				'type' => 'check',
				'label-message' => 'embeddablecontent-updatefile-create-item',
				'default' => true,
			] ] + $fields;
		}
		return $fields;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return bool|string
	 */
	public function onSubmit( array $data, Title $title, ?string $itemId ) {
		if ( $this->getUser()->isAnon() ) {
			return $this->msg( 'embeddablecontent-updatefile-anon' )->text();
		}
		$mode = (string)( $data['newVersionMode'] ?? 'none' );
		if ( $mode === 'file' || $mode === 'url' ) {
			$error = ImageUploadHelper::replaceFile(
				$this->getContext(),
				$this->getUser(),
				$title,
				$mode,
				(string)( $data['newVersionComment'] ?? '' )
			);
			if ( $error !== null ) {
				return $error;
			}
		}

		$license = $this->itemIdOrNull( (string)( $data['license'] ?? '' ) );
		$authorEntity = trim( (string)( $data['authorEntity'] ?? '' ) );
		if ( $authorEntity !== '' && !$this->allItemIds( $authorEntity ) ) {
			return $this->msg( 'embeddablecontent-updatefile-badauthor' )->text();
		}
		$sourceEntity = $this->itemIdOrNull( (string)( $data['sourceEntity'] ?? '' ) );
		$sourceUrl = trim( (string)( $data['sourceUrl'] ?? '' ) );
		if ( $sourceUrl !== '' && \EmbeddableContent\Fetch\SsrfGuard::validate( $sourceUrl ) === null ) {
			return $this->msg( 'embeddablecontent-updatefile-bad-url' )->text();
		}

		$summary = $this->msg( 'embeddablecontent-updatefile-summary' )->inContentLanguage()->text();
		$description = (string)( $data['description'] ?? '' );
		$author = (string)( $data['author'] ?? '' );
		$licenseInfo = (string)( $data['licenseInfo'] ?? '' );
		$sourceText = (string)( $data['sourceText'] ?? '' );

		try {
			if ( $itemId !== null ) {
				ImageItemCreator::updateFacts(
					$this->config,
					$itemId,
					$description,
					$license,
					$author,
					$licenseInfo,
					$sourceUrl,
					$summary,
					$this->getUser(),
					[],
					$authorEntity,
					$sourceEntity,
					$sourceText
				);
			} elseif ( !empty( $data['createItem'] ) ) {
				$label = (string)preg_replace( '/\.[^.]+$/', '', $title->getText() );
				ImageItemCreator::createOrReuse(
					$this->config,
					$this->getUser(),
					$label,
					$description,
					$title->getPrefixedText(),
					$license,
					$author,
					$licenseInfo,
					$sourceUrl,
					$summary,
					false,
					[],
					$authorEntity,
					$sourceEntity,
					$sourceText
				);
			}
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-updatefile-error', $e->getMessage() )->text();
		}

		$this->getOutput()->redirect( $title->getFullURL() );
		return true;
	}

	private function itemIdOrNull( string $value ): ?string {
		$value = strtoupper( trim( $value ) );
		return preg_match( '/^Q[1-9]\d*$/', $value ) === 1 ? $value : null;
	}

	/** Whether every comma/semicolon-separated token is an item id. */
	private function allItemIds( string $value ): bool {
		$tokens = preg_split( '/[,;]/', $value ) ?: [];
		if ( $tokens === [] ) {
			return false;
		}
		foreach ( $tokens as $token ) {
			if ( preg_match( '/^Q[1-9]\d*$/i', trim( $token ) ) !== 1 ) {
				return false;
			}
		}
		return true;
	}

	private function firstString( Item $item, ?string $propertyId ): string {
		if ( $propertyId === null || $propertyId === '' ) {
			return '';
		}
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof StringValue ) {
				return (string)$snak->getDataValue()->getValue();
			}
		}
		return '';
	}

	private function firstEntity( Item $item, ?string $propertyId ): string {
		if ( $propertyId === null || $propertyId === '' ) {
			return '';
		}
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof EntityIdValue ) {
				return $snak->getDataValue()->getEntityId()->getSerialization();
			}
		}
		return '';
	}

	/** @return string[] every entity id of a property (statement order). */
	private function entityIds( Item $item, ?string $propertyId ): array {
		if ( $propertyId === null || $propertyId === '' ) {
			return [];
		}
		$ids = [];
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$snak = $statement->getMainSnak();
			if ( $snak instanceof PropertyValueSnak && $snak->getDataValue() instanceof EntityIdValue ) {
				$ids[] = $snak->getDataValue()->getEntityId()->getSerialization();
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private function itemDescription( Item $item ): string {
		$descriptions = $item->getDescriptions()->toTextArray();
		return (string)( $descriptions['en'] ?? ( $descriptions ? reset( $descriptions ) : '' ) );
	}
}
