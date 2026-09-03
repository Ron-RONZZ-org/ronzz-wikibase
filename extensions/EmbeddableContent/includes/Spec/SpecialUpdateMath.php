<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Special:UpdateMath — re-edit an existing math-snippet item
 * (Special:UpdateMath/Q42): the Special:AddMath form prefilled from the
 * item's statements (payload decoded, real multiline), no-clobber update on
 * submit. The KaTeX live preview is rendered like on Add.
 *
 * @license GPL-2.0-or-later
 */
class SpecialUpdateMath extends SpecialUpdateContentItem {

	public function __construct() {
		parent::__construct( 'UpdateMath' );
	}

	protected function getKind(): string {
		return 'math';
	}
}
