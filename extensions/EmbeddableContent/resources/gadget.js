/* eslint-disable no-jquery/no-global-selector */
/*
 * Entity-page toolbar: "copy embed" + "copy citation" buttons, prominently
 * displayed under the page title (issue #6 §4.4 — follow-up: visible buttons
 * instead of portlet links hidden in the ⋯ "More options" menu).
 *
 * The toolbar renders only the actions that apply to the item: the embed
 * button appears when the item is embeddable (action=embed succeeds), the
 * citation button when a citation can be built (action=citation succeeds).
 * Both are read-side; the Special pages are the write-side.
 */
( function () {
	'use strict';

	var ID_PATTERN = /^Q[1-9]\d*$/;
	var entityId = null;
	var titleText = mw.config.get( 'wgTitle' ) || '';

	if ( ID_PATTERN.test( titleText ) ) {
		entityId = titleText;
	}

	function embedSnippet() {
		return '<iframe src="' + mw.util.getUrl( 'Special:Embed/' + entityId ) +
			'" loading="lazy" style="width:100%;border:0;min-height:120px"></iframe>';
	}

	function copyText( text ) {
		var done = function () {
			mw.notify( mw.msg( 'embeddablecontent-gadget-copied' ) );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text ).then( done, function () {
				fallbackCopy( text );
				done();
			} );
		}
		fallbackCopy( text );
		done();
	}

	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try {
			document.execCommand( 'copy' );
		} finally {
			document.body.removeChild( ta );
		}
	}

	function makeButton( id, messageKey, handler ) {
		var $btn = $( '<button>' )
			.attr( 'id', id )
			.attr( 'type', 'button' )
			.addClass( 'wb-embed-toolbar-btn' )
			.text( mw.msg( messageKey ) )
			.on( 'click', handler );
		return $btn;
	}

	function renderToolbar( buttons ) {
		var $toolbar = $( '<div>' )
			.addClass( 'wb-embed-toolbar' )
			.append( buttons );
		// Under the page title, above the entity view.
		$( '#firstHeading' ).after( $toolbar );
	}

	mw.loader.using( [ 'mediawiki.api', 'mediawiki.notification' ] ).then( function () {
		if ( !entityId || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		var api = new mw.Api();
		var buttons = [];
		var checkDone = 0;

		function maybeRender() {
			checkDone++;
			if ( checkDone < 2 ) {
				return;
			}
			if ( buttons.length > 0 ) {
				renderToolbar( buttons );
			}
		}

		// Embed button: only for embeddable items.
		api.get( { action: 'embed', entity: entityId, output: 'html' } ).done( function ( data ) {
			if ( !data.error ) {
				buttons.push( makeButton(
					'ca-wb-embed-copy',
					'embeddablecontent-gadget-copyembed',
					function () { copyText( embedSnippet() ); }
				) );
			}
			maybeRender();
		} ).fail( maybeRender );

		// Citation button: only when a citation can be built.
		api.get( { action: 'citation', entity: entityId, style: 'apa', output: 'text' } ).done( function ( data ) {
			var citation = ( data && data.citation && !data.error ) ? data.citation : '';
			if ( citation ) {
				buttons.push( makeButton(
					'ca-wb-embed-cite',
					'embeddablecontent-gadget-copycitation',
					function () { copyText( citation ); }
				) );
			}
			maybeRender();
		} ).fail( maybeRender );
	} );
}() );
