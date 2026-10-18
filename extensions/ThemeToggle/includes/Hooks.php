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
		if ( $skin->getSkinName() !== 'vector-2022' ) {
			return;
		}
		$out->addModules( 'ext.themeToggle' );
	}
}
