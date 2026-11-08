/**
 * Dismiss control for the Source:<label>/archivedText disclaimer banner.
 *
 * The banner is server-rendered by Hooks::onBeforePageDisplay. The dismissal
 * is deliberately TRANSIENT — it removes the banner for the current view
 * only, so a page refresh shows it again (no storage). The banner itself is
 * plain HTML, so a JS-off reader always sees it (and it prints with the
 * page; the dismiss link is hidden by sourcearchive.css in print).
 */
( function () {
	'use strict';

	function init() {
		var banner = document.querySelector( '.wb-sourcearchive-banner' );
		if ( !banner ) {
			return;
		}
		var dismiss = banner.querySelector( '.wb-sourcearchive-dismiss' );
		if ( !dismiss ) {
			return;
		}
		dismiss.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			if ( banner.parentNode ) {
				banner.parentNode.removeChild( banner );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
