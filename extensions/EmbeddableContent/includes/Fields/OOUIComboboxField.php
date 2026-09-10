<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fields;

use MediaWiki\HTMLForm\Field\HTMLComboboxField;

/**
 * The shared entity-combobox HTMLForm field.
 *
 * Renders its OOUI widget even inside a php-mode form. Special:Upload's
 * UploadForm is a plain HTMLForm (display format 'table', never OOUI — the
 * core form does not call enableOOUI/setDisplayFormat), so a `type =>
 * combobox` field renders as a php-mode `<input>` + `<datalist>`, NOT the
 * OOUI ComboBoxInputWidget the Add* pages use (their forms are
 * HTMLForm::factory('ooui')). The entity-suggest module
 * (ext.embeddableContent.entitysuggest) targets
 * `.wb-entity-combobox.oo-ui-comboBoxInputWidget`, so a php-mode input never
 * gets the entity autocomplete — the "native formatting" the Add* license
 * comboboxes have. This field forces the OOUI rendering in any display
 * format and marks the widget infusable (so `data-ooui` is emitted and the
 * JS can infuse it).
 *
 * CLASS SCOPE: the optional `wbClasses` param (a list of item ids) is
 * emitted as `data-wb-classes` on the widget; entitysuggest.js reads it and
 * passes it to action=entitysearch, so the search is restricted to items
 * that are `instance of` one of those classes (e.g. the license combobox
 * searches only license items). An empty list = unscoped.
 *
 * @license GPL-2.0-or-later
 */
class OOUIComboboxField extends HTMLComboboxField {

	/** @var string[] class item ids the search is restricted to ([] = all) */
	private array $wbClasses;

	/**
	 * @param array<string,mixed> $params
	 */
	public function __construct( $params ) {
		parent::__construct( $params );
		$this->wbClasses = array_values( array_filter(
			array_map( 'strval', (array)( $params['wbClasses'] ?? [] ) ),
			static fn ( string $id ): bool => $id !== ''
		) );
	}

	/**
	 * Table/div/php display formats call getInputHTML. Delegate to the OOUI
	 * widget (infusable → data-ooui) so the field renders like the Add*
	 * pages' comboboxes regardless of the form's display format.
	 *
	 * @param string $value
	 * @return string
	 */
	public function getInputHTML( $value ) {
		$widget = $this->getInputOOUI( $value );
		if ( $widget instanceof \OOUI\Widget ) {
			// The OOUI theme singleton is only set when the output is OOUI-
			// enabled (OutputPage::setupOOUI) — a php-mode HTMLForm never
			// initialises it, and Element::toString() on a widget fatals
			// without a theme. setupOOUI() is idempotent (once-per-process
			// guard) and no-ops when the theme is already set.
			\MediaWiki\Output\OutputPage::setupOOUI();
			$widget->setInfusable( true );
			return $widget->toString();
		}
		return parent::getInputHTML( $value );
	}

	/**
	 * Carries the class scope on the widget root as `data-wb-classes`.
	 *
	 * @param string $value
	 * @return \OOUI\Widget
	 */
	public function getInputOOUI( $value ) {
		$widget = parent::getInputOOUI( $value );
		if ( $widget instanceof \OOUI\Widget && $this->wbClasses !== [] ) {
			$widget->setAttributes( [ 'data-wb-classes' => implode( '|', $this->wbClasses ) ] );
		}
		return $widget;
	}
}
