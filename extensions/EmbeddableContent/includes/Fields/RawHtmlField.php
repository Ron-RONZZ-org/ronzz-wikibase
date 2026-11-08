<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fields;

use MediaWiki\HTMLForm\Field\HTMLInfoField;

/**
 * An HTMLForm info field that renders raw HTML in a plain <div> — NOT inside
 * the OO.ui.LabelWidget a stock HTMLInfoField uses in OOUI mode.
 *
 * The label wrapper matters: clicking any button inside a <label> fires a
 * synthetic click on the label's first labelable control (the HTML label
 * activation rule). The AddSource "Search for authors" panel is a button +
 * a results list; rendered inside a LabelWidget, clicking a result row
 * activated the "Search for authors" button instead, which re-ran the
 * search and wiped the row's dialog. This field keeps the panel in a
 * neutral container so the row's own click handler wins.
 *
 * @license GPL-2.0-or-later
 */
class RawHtmlField extends HTMLInfoField {

	/**
	 * @param string $value
	 * @return \OOUI\Widget
	 */
	public function getInputOOUI( $value ) {
		$widget = new \OOUI\Widget();
		$widget->appendContent( new \OOUI\HtmlSnippet( (string)$value ) );
		return $widget;
	}
}
