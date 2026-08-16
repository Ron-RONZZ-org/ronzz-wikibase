/* eslint-disable no-jquery/no-global-selector */
/*
 * Entity-page gadget: "copy embed" + "copy citation" (issue #6 §4.4).
 * Read-side only; the Special pages are the write-side. The citation copy
 * fetches the WikibaseCitation API (action=citation) and copies the result.
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

	function addPortletLink( id, text, handler ) {
		mw.util.addPortletLink( 'p-cactions', '#', text, id, null, null, id )
			.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				handler();
			} );
	}

	// Defer until the page (and user) is ready.
	mw.loader.using( 'mediawiki.notification' ).then( function () {
		if ( !entityId ) {
			return;
		}
		addPortletLink( 'ca-wb-embed-copy', mw.msg( 'embeddablecontent-gadget-copyembed' ), function () {
			copyText( embedSnippet() );
		} );
		mw.loader.using( 'mediawiki.api' ).then( function () {
			var api = new mw.Api();
			api.get( {
				action: 'citation',
				entity: entityId,
				style: 'apa',
				output: 'text'
			} ).done( function ( data ) {
				var citation = ( data && data.citation ) || '';
				if ( !citation ) {
					return;
				}
				addPortletLink( 'ca-wb-embed-cite', mw.msg( 'embeddablecontent-gadget-copycitation' ), function () {
					copyText( citation );
				} );
			} );
		} );
	} );
}() );
