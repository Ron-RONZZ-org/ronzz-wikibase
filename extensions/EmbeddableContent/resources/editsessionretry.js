/**
 * Edit-page CSRF-token recovery.
 *
 * Core's EditPage forces a PREVIEW when a save's edit token is stale
 * (`session_fail_preview`). Hooks::wireEditSessionRetry marks that response
 * with `wb-edit-token-refreshed` ONLY when the original POST was same-origin,
 * the user is still logged in, and this is not the retry itself. This module
 * then resubmits the re-rendered form once with its (fresh) token.
 *
 * `wbTokenRetry=1` makes the server skip the marker on the retry, so a
 * second failure can never loop. A cross-site POST or a logged-out session
 * never gets the marker and keeps core's safe preview.
 */
( function () {
	'use strict';

	$( function () {
		if ( !document.body.classList.contains( 'wb-edit-token-refreshed' ) ) {
			return;
		}
		if ( mw.user.isAnon() ) {
			return;
		}
		var form = document.getElementById( 'editform' );
		if ( !form || !document.getElementById( 'wpTextbox1' ) ) {
			return;
		}
		var marker = document.createElement( 'input' );
		marker.type = 'hidden';
		marker.name = 'wbTokenRetry';
		marker.value = '1';
		form.appendChild( marker );

		mw.notify( mw.msg( 'embeddablecontent-edit-session-retry' ) );
		var save = document.getElementById( 'wpSave' );
		if ( save ) {
			save.click();
		} else {
			form.submit();
		}
	} );
}() );
