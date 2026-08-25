<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Upload;

use MediaWiki\HTMLForm\Field\HTMLComboboxField;

/**
 * A combobox that renders its OOUI widget even inside a php-mode form.
 *
 * Special:Upload's UploadForm is a plain HTMLForm (display format 'table',
 * never OOUI — the core form does not call enableOOUI/setDisplayFormat), so
 * a `type => combobox` field renders as a php-mode `<input>` + `<datalist>`,
 * NOT the OOUI ComboBoxInputWidget the Add* pages use (their forms are
 * HTMLForm::factory('ooui')). The entity-suggest module
 * (ext.embeddableContent.entitysuggest) targets
 * `.wb-entity-combobox.oo-ui-comboBoxInputWidget`, so a php-mode input never
 * gets the entity autocomplete — the "native formatting" the Add* license
 * comboboxes have. This field forces the OOUI rendering in any display
 * format and marks the widget infusable (so `data-ooui` is emitted and the
 * JS can infuse it).
 *
 * @license GPL-2.0-or-later
 */
final class OOUIComboboxField extends HTMLComboboxField {

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
			$widget->setInfusable( true );
			return $widget->toString();
		}
		return parent::getInputHTML( $value );
	}
}
