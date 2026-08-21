<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\Content\FragmentSanitizer;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Spec\ItemIdList;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use DataValues\MonolingualTextValue;
use Wikibase\Repo\WikibaseRepo;

/**
 * Base class for the three content-creation pages. Holds ~80% of the logic
 * (issue #6 §4.1): label, payload, uniform provenance block; the kind is
 * implied by the page, there is no selector. Authors/sources must exist
 * beforehand — saving creates one item with zero nested entity writes.
 *
 * @license GPL-2.0-or-later
 */
abstract class SpecialAddContentItem extends SpecialPage {

	/** @var EmbeddableContentConfig */
	protected $config;

	public function __construct( string $pageName ) {
		parent::__construct( $pageName );
		$this->config = new EmbeddableContentConfig(
			$this->getConfig()->get( 'EmbeddableContentConfig' )
		);
	}

	/** Kind key: quotation | code | math */
	abstract protected function getKind(): string;

	public function execute( $subPage ) {
		// Standard special-page header plumbing (title from getDescription(),
		// noindex + article-related=false); required or the page renders an
		// empty <h1>/<title>.
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( 'ext.embeddableContent.embed' );
		$this->getOutput()->addModules( 'ext.embeddableContent.entitysuggest' );
		$form = HTMLForm::factory( 'ooui', $this->buildFields(), $this->getContext() );
		$form->setTitle( $this->getPageTitle() )
			->setSubmitTextMsg( 'embeddablecontent-add-submit' )
			->setSubmitCallback( [ $this, 'onSubmit' ] )
			->setSubmitID( 'wb-embed-add-form' );
		$form->show();
	}

	protected function buildFields(): array {
		$fields = [
			'label' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-add-label',
				'required' => true,
				'maxlength' => 250,
			],
			'payload' => [
				'type' => 'textarea',
				'label-message' => $this->getKind() === 'quotation'
					? 'embeddablecontent-add-quotation-payload'
					: 'embeddablecontent-add-payload',
				'required' => true,
				'rows' => 8,
			],
		];

		if ( $this->getKind() === 'quotation' ) {
			// All Wikibase-supported languages (500+), not just the
			// config fallbacks: combobox = pick from the list or type a code.
			$languageNames = \MediaWiki\MediaWikiServices::getInstance()
				->getLanguageNameUtils()
				->getLanguageNames();
			$fields['language'] = [
				'type' => 'combobox',
				'label-message' => 'embeddablecontent-add-language',
				'options' => array_flip( $languageNames ),
				'default' => $this->getLanguage()->getCode(),
			];
		} elseif ( $this->getKind() === 'code' ) {
			$lexers = [];
			foreach ( array_keys( $this->config->lexerItemIds() ) as $lexer ) {
				$lexers[$lexer] = $lexer;
			}
			$fields['lexer'] = [
				'type' => 'select',
				'label-message' => 'embeddablecontent-add-language',
				'options' => $lexers,
				'default' => 'text',
			];
		}

		// Uniform provenance block (issue #6 §4.1). Issue #7: the plain
		// item-id fields are entity search+autofill comboboxes backed by
		// wbsearchentities (ext.embeddableContent.entitysuggest); the
		// submitted value stays an item id (parseOptionalItemId unchanged).
		$entityCombobox = static function ( string $messageKey, bool $required, bool $multi = false ): array {
			return [
				'type' => 'combobox',
				'options' => [],
				'label-message' => $messageKey,
				'required' => $required,
				'cssclass' => $multi ? 'wb-entity-combobox wb-entity-combobox-multi' : 'wb-entity-combobox',
				'help-message' => $multi
					? 'embeddablecontent-entityid-multiple-hint'
					: 'embeddablecontent-add-entityid-help',
			];
		};
		$fields += [
			'attributedTo' => $entityCombobox( 'embeddablecontent-add-attributedto', $this->getKind() === 'quotation' ),
			'sourceUrl' => [
				'type' => 'url',
				'label-message' => 'embeddablecontent-add-sourceurl',
			],
			'source' => $entityCombobox( 'embeddablecontent-add-source', false ),
			'date' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-add-date',
				'placeholder' => 'YYYY-MM-DD',
				'help-message' => 'embeddablecontent-add-date-help',
			],
		];

		// Content-subject entity fields (issue follow-up): math 'describes'
		// (what the expression is about), code 'implementation of' (the
		// algorithm/concept the code implements). Optional, multi-value
		// entity comboboxes — an expression can describe several concepts,
		// code can implement several algorithms.
		if ( $this->getKind() === 'math' && $this->config->describesPropertyId() !== null ) {
			$fields['describes'] = $entityCombobox( 'embeddablecontent-add-describes', false, true );
		} elseif ( $this->getKind() === 'code' && $this->config->implementationOfPropertyId() !== null ) {
			$fields['implementationOf'] = $entityCombobox( 'embeddablecontent-add-implementationof', false, true );
		}
		return $fields;
	}

	/**
	 * @param array $data
	 * @return bool|string true on success, error string otherwise
	 */
	public function onSubmit( array $data ) {
		$label = trim( (string)$data['label'] );
		$payload = trim( (string)$data['payload'] );
		if ( $label === '' || $payload === '' ) {
			return $this->msg( 'embeddablecontent-add-error-required' )->text();
		}

		$classId = $this->config->classIds()[$this->getKind()] ?? null;
		$payloadPropertyId = $this->config->payloadPropertyIds()[$this->getKind()] ?? null;
		if ( $classId === null || $payloadPropertyId === null ) {
			return $this->msg( 'embeddablecontent-add-error-config' )->text();
		}

		// The quotation language combobox accepts any of the 500+ languages;
		// reject garbage codes instead of letting Wikibase fail on save.
		$language = (string)( $data['language'] ?? $this->getLanguage()->getCode() );
		if (
			$this->getKind() === 'quotation'
			&& !\MediaWiki\MediaWikiServices::getInstance()->getLanguageNameUtils()->isValidCode( $language )
		) {
			return $this->msg( 'embeddablecontent-add-error-badlanguage' )->text();
		}

		$attributedTo = $this->parseOptionalItemId( (string)$data['attributedTo'] );
		$source = $this->parseOptionalItemId( (string)$data['source'] );
		if ( (string)$data['attributedTo'] !== '' && $attributedTo === null ) {
			return $this->msg( 'embeddablecontent-add-error-baditemid', 'attributed to' )->text();
		}
		if ( (string)$data['source'] !== '' && $source === null ) {
			return $this->msg( 'embeddablecontent-add-error-baditemid', 'source' )->text();
		}

		$sourceUrl = null;
		if ( (string)$data['sourceUrl'] !== '' ) {
			$sourceUrl = ( new FragmentSanitizer() )->validateUrl( (string)$data['sourceUrl'] );
			if ( $sourceUrl === null ) {
				return $this->msg( 'embeddablecontent-add-error-badurl' )->text();
			}
		}

		$date = $this->parseDate( (string)$data['date'] );
		if ( (string)$data['date'] !== '' && $date === null ) {
			return $this->msg( 'embeddablecontent-add-error-baditemid', 'date' )->text();
		}

		// Save 1: create the item with the label (the store assigns the id).
		$item = new Item();
		$item->setLabel( $this->getLanguage()->getCode(), $label );

		try {
			WikibaseRepo::getEntityStore()->saveEntity(
				$item,
				$this->msg( 'embeddablecontent-add-edit-summary', $label )->inContentLanguage()->text(),
				$this->getUser(),
				EDIT_NEW
			);
		} catch ( \Exception $e ) {
			return $this->msg( 'embeddablecontent-add-error-save' )->text();
		}

		// Save 2: add class, payload and provenance statements (correct GUIDs
		// now that the id is known). Still one item, zero nested entities.
		$parser = WikibaseRepo::getEntityIdParser();
		$guidGenerator = new GuidGenerator();
		$itemValue = static function ( string $idString ) {
			return new EntityIdValue( new ItemId( $idString ) );
		};
		$add = static function ( $propertyIdString, $value ) use ( $item, $parser, $guidGenerator ): void {
			$item->getStatements()->addNewStatement(
				new PropertyValueSnak( $parser->parse( $propertyIdString ), $value ),
				null,
				null,
				$guidGenerator->newGuid( $item->getId() )
			);
		};

		$add( $this->config->instanceOfPropertyId(), $itemValue( $classId ) );

		if ( $this->getKind() === 'quotation' ) {
			$add( $payloadPropertyId, new MonolingualTextValue( (string)$data['language'], $payload ) );
		} else {
			$add( $payloadPropertyId, new StringValue( $payload ) );
		}

		if ( $this->getKind() === 'code' ) {
			$languageItemId = $this->config->lexerItemIds()[(string)$data['lexer']] ?? null;
			if ( $languageItemId !== null ) {
				$add( $this->config->programmingLanguagePropertyId(), $itemValue( $languageItemId ) );
			}
		}

		if ( $attributedTo !== null ) {
			$add( $this->config->provenancePropertyIds()['attributedTo'], new EntityIdValue( $attributedTo ) );
		}
		if ( $source !== null ) {
			$add( $this->config->provenancePropertyIds()['source'], new EntityIdValue( $source ) );
		}
		if ( $sourceUrl !== null ) {
			$add( $this->config->provenancePropertyIds()['sourceUrl'], new StringValue( $sourceUrl ) );
		}
		if ( $date !== null ) {
			$add( $this->config->provenancePropertyIds()['date'], $date );
		}

		// Content-subject statements (issue follow-up): math 'describes',
		// code 'implementation of'. Optional, multi-valued — one statement
		// per valid item id; any invalid element is a hard error (same
		// strictness as the single-value contract below).
		if ( $this->getKind() === 'math' && $this->config->describesPropertyId() !== null ) {
			$result = $this->splitItemIds( (string)$data['describes'], 'describes' );
			if ( $result['error'] !== null ) {
				return $result['error'];
			}
			foreach ( $result['ids'] as $id ) {
				$add( $this->config->describesPropertyId(), new EntityIdValue( $id ) );
			}
		}
		if ( $this->getKind() === 'code' && $this->config->implementationOfPropertyId() !== null ) {
			$result = $this->splitItemIds( (string)$data['implementationOf'], 'implementation of' );
			if ( $result['error'] !== null ) {
				return $result['error'];
			}
			foreach ( $result['ids'] as $id ) {
				$add( $this->config->implementationOfPropertyId(), new EntityIdValue( $id ) );
			}
		}

		try {
			WikibaseRepo::getEntityStore()->saveEntity(
				$item,
				$this->msg( 'embeddablecontent-add-edit-summary', $label )->inContentLanguage()->text(),
				$this->getUser(),
				EDIT_UPDATE
			);
		} catch ( \Exception $e ) {
			return $this->msg( 'embeddablecontent-add-error-save' )->text();
		}

		$this->createdItemId = $item->getId();
		// Modern HTMLForm has no onSuccess step — redirect to the created
		// item here, otherwise the page just renders empty after submit.
		$this->onSubmitSuccess();
		return true;
	}

	public function onSubmitSuccess() {
		$title = WikibaseRepo::getEntityTitleStoreLookup()->getTitleForId( $this->createdItemId );
		$this->getOutput()->redirect( $title ? $title->getFullURL() : $this->getPageTitle()->getFullURL() );
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

	/**
	 * Parses a multi-valued entity-field input (comma/semicolon-separated
	 * item ids). Empty input → no ids; a non-empty input with ANY invalid
	 * element is an error (returned as a user-facing message string), so a
	 * typo in one id never silently drops statements.
	 *
	 * @return array{ids: ItemId[], error: ?string}
	 */
	private function splitItemIds( string $input, string $fieldLabel ): array {
		$candidates = ItemIdList::split( $input );
		if ( $candidates === [] ) {
			return [ 'ids' => [], 'error' => null ];
		}
		$parsed = [];
		foreach ( $candidates as $candidate ) {
			$id = $this->parseOptionalItemId( $candidate );
			if ( $id === null ) {
				return [ 'ids' => [], 'error' => $this->msg( 'embeddablecontent-add-error-baditemid', $fieldLabel )->text() ];
			}
			$parsed[] = $id;
		}
		return [ 'ids' => $parsed, 'error' => null ];
	}

	private function parseDate( string $input ): ?TimeValue {
		$input = trim( $input );
		if ( $input === '' ) {
			return null;
		}
		$dt = \DateTime::createFromFormat( '!Y-m-d', $input );
		if ( $dt === false ) {
			return null;
		}
		return new TimeValue(
			'+' . $dt->format( 'Y-m-d' ) . 'T00:00:00Z',
			0, // timezone
			0, // before
			0, // after
			TimeValue::PRECISION_DAY,
			'http://www.wikidata.org/entity/Q1985727'
		);
	}

	/** @var ItemId|null */
	private $createdItemId;

	/**
	 * MW 1.43+ resolves the special-page description from the bare lowercase
	 * page name (strtolower( $this->mName )); our i18n uses the legacy
	 * `special-<name>` keys. Override to keep the pages listed on
	 * Special:SpecialPages (T360723 skips pages whose description message
	 * is disabled) and to render a proper page title — same pattern as
	 * Wikibase's SpecialWikibasePage.
	 */
	public function getDescription() {
		return $this->msg( 'special-' . strtolower( $this->getName() ) );
	}

	protected function getGroupName(): string {
		return 'wikibase';
	}
}
