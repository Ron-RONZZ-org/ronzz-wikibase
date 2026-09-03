<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Special:UpdateQuotation — re-edit an existing quotation item
 * (Special:UpdateQuotation/Q42): the Special:AddQuotation form prefilled
 * from the item's statements (payload decoded, real multiline), no-clobber
 * update on submit.
 *
 * @license GPL-2.0-or-later
 */
class SpecialUpdateQuotation extends SpecialUpdateContentItem {

	public function __construct() {
		parent::__construct( 'UpdateQuotation' );
	}

	protected function getKind(): string {
		return 'quotation';
	}
}
