/*
 * Rendered preview of a content item on its Item: page (quotation / math /
 * code snippet). Content items carry NO classic page — the Item page is the
 * only place to see them — so the server sets wbContentPreviewItem (the
 * Q-id, only for items classified under a content class) and this module
 * fetches the same framed embed fragment the embed surfaces render
 * (action=embed) and injects it directly BELOW the entity toolbar
 * (.wb-embed-toolbar), so the reader sees the actual content next to its
 * statements.
 *
 * The embed module (ext.embeddableContent.embed) supplies the chrome CSS
 * and the KaTeX / highlight.js renderers. Those run their initial pass at
 * script execution — before this async fetch resolves — so the module fires
 * the shared `ext.embeddableContent.embedContentAdded` hook after injecting
 * to ask them to (re-)render the new nodes.
 */
( function () {
	'use strict';

	var ID_PATTERN = /^Q[1-9]\d*$/;
	var item = mw.config.get( 'wbContentPreviewItem' );
	if ( typeof item !== 'string' || !ID_PATTERN.test( item ) ) {
		return;
	}
	if ( $( '#firstHeading' ).length === 0 ) {
		return;
	}

	/**
	 * Inserts the preview after the toolbar when it already exists, else
	 * after the page heading. gadget.js / updatebutton.js create the toolbar
	 * with `$( '#firstHeading' ).after( toolbar )`, which lands the toolbar
	 * ABOVE a preview inserted after the heading — the preview stays
	 * directly below the toolbar whichever module renders first.
	 */
	function mount( html ) {
		var $preview = $( '<div class="wb-content-preview"></div>' ).html( html );
		var $toolbar = $( '.wb-embed-toolbar' );
		if ( $toolbar.length > 0 ) {
			$toolbar.after( $preview );
		} else {
			$( '#firstHeading' ).after( $preview );
		}
		// Let math.js / code.js typeset KaTeX and highlight the injected
		// code (their initial pass already ran).
		mw.hook( 'ext.embeddableContent.embedContentAdded' ).fire( $preview );
	}

	mw.loader.using( [ 'mediawiki.api', 'ext.embeddableContent.embed' ] ).then( function () {
		var api = new mw.Api();
		api.get( { action: 'embed', entity: item, output: 'html' } ).done( function ( data ) {
			var html = data && data.embed && data.embed.html;
			// Not embeddable (missing payload / wrong class): render nothing —
			// the Item page still shows the statements.
			if ( !data || data.error || typeof html !== 'string' || html === '' ) {
				return;
			}
			mount( html );
		} );
	} );
}() );
