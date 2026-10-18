<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ThemeToggle;

use MediaWiki\Output\Hook\BeforePageDisplayHook;

/**
 * Loads the quick day/night toggle on the Vector 2022 skin.
 *
 * The toggle only manipulates the skin's own `vector-theme` client preference
 * (`skin-theme-clientpref-day|night|os`), so the server-side theme, the
 * Appearance menu and this button always agree. No separate preference or
 * stylesheet is introduced.
 *
 * @license GPL-2.0-or-later
 */
class Hooks implements BeforePageDisplayHook {

	/**
	 * @param \MediaWiki\Output\OutputPage $out
	 * @param \MediaWiki\Skin\Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		// `vector-2022` is the modern skin code; `vector` is the legacy skin
		// (and, on some Vector versions, the modern layout behind a version
		// flag). The client module itself checks for `body.skin-vector-2022`,
		// so loading it on both is harmless on legacy and future-proof.
		if ( !in_array( $skin->getSkinName(), [ 'vector-2022', 'vector' ], true ) ) {
			return;
		}
		$out->addModules( 'ext.themeToggle' );
	}
}
