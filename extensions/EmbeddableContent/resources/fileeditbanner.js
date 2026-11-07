/**
 * Dismiss control for the File: edit-page "metadata lives on the semantic
 * item" warning. The banner itself is server-rendered by
 * Hooks::onEditPage__showEditForm_initial; the dismissal is remembered for
 * the browser session, per File page.
 */
( function () {
	'use strict';

	mw.loader.using( 'mediawiki.storage' ).then( function () {
		var banner = document.getElementById( 'wb-file-edit-banner' );
		if ( !banner ) {
			return;
		}
		var key = 'wb-file-edit-banner:' + ( mw.config.get( 'wgPageName' ) || '' );
		if ( mw.storage.session.get( key ) ) {
			banner.parentNode.removeChild( banner );
			return;
		}
		var dismiss = banner.querySelector( '.wb-file-edit-banner-dismiss' );
		if ( !dismiss ) {
			return;
		}
		dismiss.addEventListener( 'click', function () {
			mw.storage.session.set( key, '1' );
			banner.parentNode.removeChild( banner );
		} );
	} );
}() );
