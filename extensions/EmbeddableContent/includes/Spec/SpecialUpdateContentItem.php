<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\MonolingualTextValue;
use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\Content\PayloadCodec;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Base class for the three content-item update pages (quotation / math /
 * code-snippet) — Special:UpdateQuotation/Q42 and siblings: the "Edit
 * content" surface for an EXISTING content item.
 *
 * It extends SpecialAddContentItem so the edit form is the exact same form
 * as the Add* page (buildFields), prefilled from the item's statements
 * (recordFromItem — the reverse of SpecialContentFlowService::statementSpecs):
 * the payload is DECODED with PayloadCodec, so multi-line content appears
 * exactly as it was entered in Add (real newlines in the textarea, not the
 * escaped-at-rest form), the quotation language and the code lexer are
 * pre-selected, and the subject lists/provenance fields carry their ids.
 *
 * The submit follows the Special:Update* no-clobber contract through the
 * shared SpecialContentFlowService (the same pipeline the
 * action=addspecialcontent qid update runs): only the managed statements
 * for which the form provides a NEW non-empty value are replaced — a blank
 * field keeps the existing statement (removal is an explicit item-page
 * edit); the label is written back in the term language it was prefilled
 * from; the class never changes. The kind is implied by the page; the URL
 * carries the item id (Special:UpdateQuotation/Q42).
 *
 * @license GPL-2.0-or-later
 */
abstract class SpecialUpdateContentItem extends SpecialAddContentItem {

	/** @var Item|null the item being edited (set by execute) */
	protected ?Item $updateItem = null;

	/** @var string|null the language of the prefilled label term */
	protected ?string $updateLabelLanguage = null;

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( 'ext.embeddableContent.embed' );
		$this->getOutput()->addModules( 'ext.embeddableContent.entitysuggest' );

		$itemId = $this->itemIdFromSubPage( $subPage );
		if ( $itemId === null ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-update-badid' )->escaped() )
			);
			return;
		}
		$item = $this->loadContentItem( $itemId );
		if ( !$item instanceof Item ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-update-notfound', $itemId )->escaped() )
			);
			return;
		}
		$classId = $this->config->classIds()[$this->getKind()] ?? null;
		if ( $classId === null || !$this->itemCarriesClass( $item, $classId ) ) {
			// The item is not (anymore) of the class this Update page manages.
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-update-noclass', $itemId )->escaped() )
			);
			return;
		}
		$this->updateItem = $item;

		// Prefill the shared Add* form from the item's statements, then
		// drop the create-only "Add more" button.
		$fields = $this->prefilledFields( $this->recordFromItem( $item ) );
		$form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
		$form->setTitle( $this->getPageTitle( $itemId ) )
			->setSubmitTextMsg( 'embeddablecontent-update-submit' )
			->setSubmitCallback( [ $this, 'onUpdateSubmit' ] )
			->setSubmitID( 'wb-ext-update-content' )
			->setWrapperLegendMsg( 'embeddablecontent-update-content-legend' );
		$form->show();
		if ( $this->getKind() === 'math' ) {
			$this->addMathPreviewBox();
		}
	}

	/**
	 * The Add* form fields with the item's current values as defaults. The
	 * record keys map 1:1 onto the form fields (label → label,
	 * content → payload, language/lexer/attributedTo/sourceUrl/source/date
	 * and the subject lists keep their names); empty record values are NOT
	 * set, so the Add* builder defaults (e.g. the language combobox = the
	 * UI language, the lexer = "text") show for fields the item lacks.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function prefilledFields( array $record ): array {
		$fields = $this->buildFields();
		// The "Add more" quick-entry button makes no sense on an edit form.
		unset( $fields['addMore'] );
		$defaults = [ 'label' => $record['label'] ?? '', 'payload' => $record['content'] ?? '' ];
		foreach ( [
			'language', 'lexer', 'attributedTo', 'sourceUrl', 'source',
			'date', 'describes', 'implementationOf',
		] as $name ) {
			if ( !empty( $record[$name] ) ) {
				$defaults[$name] = $record[$name];
			}
		}
		foreach ( $defaults as $name => $value ) {
			if ( isset( $fields[$name] ) ) {
				$fields[$name]['default'] = $value;
			}
		}
		return $fields;
	}

	/**
	 * Reverse-maps the item's statements onto the Add* record shape (the
	 * reverse of SpecialContentFlowService::statementSpecs): label (in a
	 * config fallback language when possible), DECODED payload, quotation
	 * language, code lexer, provenance and subject lists.
	 *
	 * @return array<string,mixed>
	 */
	protected function recordFromItem( Item $item ): array {
		$record = [];
		$labelLanguage = $this->pickLabelLanguage( $item );
		$this->updateLabelLanguage = $labelLanguage;
		$labels = $item->getLabels()->toTextArray();
		$record['label'] = $labels[$labelLanguage] ?? '';
		$record['labelLanguage'] = $labelLanguage;

		$kind = $this->getKind();
		$payloadPropertyId = $this->config->payloadPropertyIds()[$kind] ?? null;
		if ( $payloadPropertyId !== null ) {
			foreach ( $this->statementValues( $item, $payloadPropertyId ) as $value ) {
				if ( !isset( $value['text'] ) ) {
					continue;
				}
				$record['content'] = PayloadCodec::decode( $value['text'] );
				if ( isset( $value['language'] ) ) {
					// The quotation payload is a MonolingualTextValue — the
					// language combobox follows the stored term.
					$record['language'] = $value['language'];
				}
				break;
			}
		}

		if ( $kind === 'code' ) {
			foreach ( $this->statementValues( $item, $this->config->programmingLanguagePropertyId() ) as $value ) {
				if ( !isset( $value['entity'] ) ) {
					continue;
				}
				$lexer = $this->config->lexerForItemId( $value['entity'] );
				if ( $lexer !== null ) {
					$record['lexer'] = $lexer;
				}
				break;
			}
		}

		$provenance = $this->config->provenancePropertyIds();
		foreach ( [ 'attributedTo', 'source' ] as $field ) {
			$propertyId = $provenance[$field] ?? null;
			if ( $propertyId === null ) {
				continue;
			}
			foreach ( $this->statementValues( $item, $propertyId ) as $value ) {
				if ( isset( $value['entity'] ) ) {
					$record[$field] = $value['entity'];
					break;
				}
			}
		}
		if ( isset( $provenance['sourceUrl'] ) ) {
			foreach ( $this->statementValues( $item, $provenance['sourceUrl'] ) as $value ) {
				if ( isset( $value['text'] ) ) {
					$record['sourceUrl'] = $value['text'];
					break;
				}
			}
		}
		if ( isset( $provenance['date'] ) ) {
			foreach ( $this->statementValues( $item, $provenance['date'] ) as $value ) {
				if ( isset( $value['time'] ) && preg_match( '/^[+-](\d{4}-\d{2}-\d{2})T/', $value['time'], $m ) === 1 ) {
					$record['date'] = $m[1];
					break;
				}
			}
		}

		// Content-subject lists (math describes / code implementation of).
		foreach ( [
			'math' => [ 'describes', $this->config->describesPropertyId() ],
			'code' => [ 'implementationOf', $this->config->implementationOfPropertyId() ],
		] as $subjectKind => [ $field, $propertyId ] ) {
			if ( $kind !== $subjectKind || $propertyId === null ) {
				continue;
			}
			$ids = [];
			foreach ( $this->statementValues( $item, $propertyId ) as $value ) {
				if ( isset( $value['entity'] ) ) {
					$ids[] = $value['entity'];
				}
			}
			if ( $ids !== [] ) {
				$record[$field] = implode( ', ', $ids );
			}
		}

		return $record;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return bool|string true on success, error string otherwise
	 */
	public function onUpdateSubmit( array $data ) {
		// The submit writes the item: login-gated like the other write
		// surfaces (the page LOADS stay open — an anonymous visitor can
		// read the prefilled form).
		if ( $this->getUser()->isAnon() ) {
			return $this->msg( 'embeddablecontent-add-error-anon' )->text();
		}
		$item = $this->updateItem;
		if ( !$item instanceof Item || $item->getId() === null ) {
			return $this->msg( 'embeddablecontent-update-notfound', '' )->text();
		}
		$itemId = $item->getId()->getSerialization();

		$converted = $this->flowRecordFromForm( $data );
		if ( $converted['error'] !== null ) {
			return $converted['error'];
		}
		$record = $converted['record'];
		// No label-language field renders on the form — flowRecordFromForm
		// would default to the UI language and write a second-language
		// term. The label is prefilled from one term language; write it
		// back there (blank label keeps the existing term either way).
		if ( $this->updateLabelLanguage !== null ) {
			$record['labelLanguage'] = $this->updateLabelLanguage;
		}

		$error = $this->contentFlow()->prepare( $converted['kind'], $record, false );
		if ( $error !== null ) {
			return $this->msg( 'embeddablecontent-add-error-save' )->text() . ' ' . $error;
		}
		try {
			// A fresh read of the item (the update must not clobber edits
			// made between the page load and the submit).
			$fresh = WikibaseRepo::getEntityLookup()->getEntity( $item->getId() );
			if ( !$fresh instanceof Item ) {
				return $this->msg( 'embeddablecontent-update-notfound', $itemId )->text();
			}
			// The source(s) BEFORE the update — a re-sourced quotation must
			// refresh the OLD source page's auto-link too.
			$previousSources = $this->sourceIdsOf( $fresh );
			$this->contentFlow()->applyUpdate( $converted['kind'], $fresh, $record );
			$label = trim( (string)( $record['label'] ?? '' ) );
			if ( $label === '' ) {
				$label = $this->updateItemLabel( $fresh );
			}
			WikibaseRepo::getEntityStore()->saveEntity(
				$fresh,
				$this->msg( 'embeddablecontent-update-edit-summary', $label )->inContentLanguage()->text(),
				$this->getUser(),
				EDIT_UPDATE
			);
			$invalidSources = $previousSources;
			if ( !empty( $record['source'] ) ) {
				$invalidSources[] = $record['source'];
			}
			\EmbeddableContent\Spec\QuotationLookup::invalidateSourcePages( $invalidSources );
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-update-error', get_class( $e ), $e->getMessage() )->text();
		}
		$this->getOutput()->redirect(
			WikibaseRepo::getEntityTitleStoreLookup()->getTitleForId( $item->getId() )->getFullURL()
		);
		return true;
	}

	/**
	 * The source item ids currently on the item (the `source` provenance
	 * statements), for invalidating the old source's page on re-source.
	 *
	 * @return string[]
	 */
	private function sourceIdsOf( Item $item ): array {
		$propertyId = $this->config->provenancePropertyIds()['source'] ?? null;
		if ( $propertyId === null ) {
			return [];
		}
		$ids = [];
		foreach ( $this->statementValues( $item, $propertyId ) as $value ) {
			if ( isset( $value['entity'] ) ) {
				$ids[] = $value['entity'];
			}
		}
		return $ids;
	}

	/** The label term shown in the edit summary when the form label is blank. */
	private function updateItemLabel( Item $item ): string {
		if ( $this->updateLabelLanguage !== null ) {
			$labels = $item->getLabels()->toTextArray();
			if ( isset( $labels[$this->updateLabelLanguage] ) ) {
				return $labels[$this->updateLabelLanguage];
			}
		}
		return $item->getId() ? $item->getId()->getSerialization() : '';
	}

	/**
	 * The language the label is shown + written in: the first config
	 * fallback language the item has a term for; otherwise the item's
	 * first available label language; 'en' when the item has no label.
	 */
	private function pickLabelLanguage( Item $item ): string {
		$labels = $item->getLabels()->toTextArray();
		if ( $labels === [] ) {
			return 'en';
		}
		foreach ( $this->config->fallbackLanguages() as $language ) {
			if ( isset( $labels[$language] ) ) {
				return $language;
			}
		}
		return (string)array_key_first( $labels );
	}

	private function itemIdFromSubPage( $subPage ): ?string {
		if ( !is_string( $subPage ) || trim( $subPage ) === '' ) {
			return null;
		}
		try {
			$id = WikibaseRepo::getEntityIdParser()->parse( trim( $subPage ) );
			return $id instanceof ItemId ? $id->getSerialization() : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function loadContentItem( string $itemId ): ?Item {
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
			return $entity instanceof Item ? $entity : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Whether the item carries the given class among its instance-of
	 * statements (the class is fixed on update).
	 */
	private function itemCarriesClass( Item $item, string $classId ): bool {
		foreach ( $this->statementValues( $item, $this->config->instanceOfPropertyId() ) as $value ) {
			if ( ( $value['entity'] ?? null ) === $classId ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads the statement values of one property: monolingual text (the
	 * quotation payload — language + escaped text), plain string, entity
	 * id and time values.
	 *
	 * @return array<int,array{language?:string,text?:string,entity?:string,time?:string}>
	 */
	private function statementValues( Item $item, string $propertyId ): array {
		$out = [];
		foreach ( $item->getStatements() as $statement ) {
			if ( $statement->getPropertyId()->getSerialization() !== $propertyId ) {
				continue;
			}
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof MonolingualTextValue ) {
				$out[] = [ 'language' => $value->getLanguageCode(), 'text' => $value->getText() ];
			} elseif ( $value instanceof StringValue ) {
				$out[] = [ 'text' => $value->getValue() ];
			} elseif ( $value instanceof EntityIdValue ) {
				$out[] = [ 'entity' => $value->getEntityId()->getSerialization() ];
			} elseif ( $value instanceof TimeValue ) {
				$out[] = [ 'time' => $value->getTime() ];
			}
		}
		return $out;
	}
}
