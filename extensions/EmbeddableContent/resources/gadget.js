/* eslint-disable no-jquery/no-global-selector */
/*
 * Entity-page toolbar: "copy embed" + "copy citation" buttons, prominently
 * displayed under the page title (issue #6 §4.4 — follow-up: visible buttons
 * instead of portlet links hidden in the ⋯ "More options" menu).
 *
 * Embed snippets use an ABSOLUTE URL (wgServer + path) — the iframe is meant
 * to be pasted on third-party sites. Multi-language quotations offer a
 * language selector: auto (server negotiates), all languages (?lang=all), or
 * a specific language.
 *
 * The toolbar renders only the actions that apply to the item: the embed
 * button appears when the item is embeddable (action=embed succeeds), the
 * citation button when a citation can be built (action=citation succeeds).
 */
( function () {
	'use strict';

	var ID_PATTERN = /^Q[1-9]\d*$/;
	var entityId = null;
	var titleText = mw.config.get( 'wgTitle' ) || '';
	var embedLang = ''; // '' = auto, 'all' = all languages, else a language code

	if ( ID_PATTERN.test( titleText ) ) {
		entityId = titleText;
	}

	function embedSnippet() {
		var server = mw.config.get( 'wgServer' ) || '';
		var params = embedLang ? { lang: embedLang } : {};
		return '<iframe src="' + server + mw.util.getUrl( 'Special:Embed/' + entityId, params ) +
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
		return $( '<button>' )
			.attr( 'id', id )
			.attr( 'type', 'button' )
			.addClass( 'wb-embed-toolbar-btn' )
			.text( mw.msg( messageKey ) )
			.on( 'click', handler );
	}

	/**
	 * Embed button + (for multi-language quotations) a language selector.
	 *
	 * @param {Object} languages code => text, from the embed API response
	 * @return {jQuery} toolbar children for the embed action
	 */
	function embedControls( languages ) {
		var $btn = makeButton( 'ca-wb-embed-copy', 'embeddablecontent-gadget-copyembed', function () {
			copyText( embedSnippet() );
		} );
		var controls = [ $btn ];
		if ( languages && Object.keys( languages ).length > 1 ) {
			var $select = $( '<select>' )
				.addClass( 'wb-embed-toolbar-lang' )
				.append( $( '<option>' ).val( '' ).text( mw.msg( 'embeddablecontent-gadget-embed-auto' ) ) )
				.append( $( '<option>' ).val( 'all' ).text( mw.msg( 'embeddablecontent-gadget-embed-all' ) ) );
			Object.keys( languages ).forEach( function ( code ) {
				$select.append( $( '<option>' ).val( code ).text( code ) );
			} );
			$select.on( 'change', function () {
				embedLang = $select.val();
			} );
			controls.push( $select );
		}
		return controls;
	}

	function renderToolbar( children ) {
		// Under the page title, above the entity view.
		$( '#firstHeading' ).after(
			$( '<div>' ).addClass( 'wb-embed-toolbar' ).append( children )
		);
	}

	mw.loader.using( [ 'mediawiki.api', 'mediawiki.notification' ] ).then( function () {
		if ( !entityId || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		var api = new mw.Api();
		var children = [];
		var checkDone = 0;

		function maybeRender() {
			checkDone++;
			if ( checkDone < 2 ) {
				return;
			}
			if ( children.length > 0 ) {
				renderToolbar( children );
			}
		}

		// Embed button: only for embeddable items (the API reports the
		// available payload languages for the selector).
		api.get( { action: 'embed', entity: entityId, output: 'html' } ).done( function ( data ) {
			if ( !data.error && data.embed ) {
				children = children.concat( embedControls( data.embed.languages ) );
			}
			maybeRender();
		} ).fail( maybeRender );

		// Citation button: only when a citation can be built.
		api.get( { action: 'citation', entity: entityId, style: 'apa', output: 'text' } ).done( function ( data ) {
			var citation = ( data && data.citation && !data.error ) ? data.citation : '';
			if ( citation ) {
				children.push( makeButton(
					'ca-wb-embed-cite',
					'embeddablecontent-gadget-copycitation',
					function () { copyText( citation ); }
				) );
			}
			maybeRender();
		} ).fail( maybeRender );
	} );
}() );
