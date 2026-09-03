<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Special:UpdateCodeSnippet — re-edit an existing code-snippet item
 * (Special:UpdateCodeSnippet/Q42): the Special:AddCodeSnippet form
 * prefilled from the item's statements (payload decoded, real multiline,
 * lexer pre-selected), no-clobber update on submit.
 *
 * @license GPL-2.0-or-later
 */
class SpecialUpdateCodeSnippet extends SpecialUpdateContentItem {

	public function __construct() {
		parent::__construct( 'UpdateCodeSnippet' );
	}

	protected function getKind(): string {
		return 'code';
	}
}
