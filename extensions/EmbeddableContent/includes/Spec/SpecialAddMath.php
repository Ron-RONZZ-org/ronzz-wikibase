<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Special:AddMath — one of the three content-creation pages (issue #6 §4.1).
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddMath extends SpecialAddContentItem {

	public function __construct() {
		parent::__construct( 'AddMath' );
	}

	protected function getKind(): string {
		return 'math';
	}
}
