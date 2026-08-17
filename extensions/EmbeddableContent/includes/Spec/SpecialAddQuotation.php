<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Special:AddQuotation — one of the three content-creation pages (issue #6 §4.1).
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddQuotation extends SpecialAddContentItem {

	public function __construct() {
		parent::__construct( 'AddQuotation' );
	}

	protected function getKind(): string {
		return 'quotation';
	}
}
