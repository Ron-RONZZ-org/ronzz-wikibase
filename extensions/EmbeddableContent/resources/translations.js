/**
 * AddQuotation "Add translation" — a confirmation guard for the translation
 * rows' delete control.
 *
 * The rows come from the HTMLForm `cloner` primitive (resources/translations
 * only styles/behaves; the add/remove + array submission are the cloner's).
 * Its own enhancement removes a row on the delete click; this capture-phase
 * listener intercepts that click first and asks for confirmation
 * (OO.ui.confirm) — the delete control carries the "✕" label
 * (embeddablecontent-add-translation-delete).
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		var button = target && target.closest
			? target.closest( '.mw-htmlform-cloner-delete-button' )
			: null;
		if ( !button ) {
			return;
		}
		var row = button.closest( 'li.mw-htmlform-cloner-li' );
		if ( !row ) {
			return;
		}
		// Beat the cloner's own click handler (bound during enhancement).
		event.preventDefault();
		event.stopImmediatePropagation();

		mw.loader.using( 'oojs-ui' ).then( function () {
			OO.ui.confirm( mw.msg( 'embeddablecontent-add-translation-confirm' ) )
				.done( function ( confirmed ) {
					if ( confirmed && row.parentNode ) {
						row.parentNode.removeChild( row );
					}
				} );
		} );
	}, true );
}() );
