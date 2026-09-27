/**
 * File: page toolbar — "Copy internal embed code" (`[[File:xxx]]`) and
 * "Copy direct link" (the media URL, e.g. /images/1/1b/Atom.png) buttons
 * rendered INLINE to the right of the file-name title.
 *
 * The server resolves the file name + media URL (Hooks::onBeforePageDisplay,
 * NS_FILE branch) into wbFileName / wbFileUrl, so the module needs no API
 * roundtrip.
 *
 * The same module completes a Special:Upload submission whose destination is
 * this File: page (BeforePageRedirect appends the hand-off params):
 *  - ?wbuploadcopy=1 — the "Copy internal embed code" checkbox was ticked on
 *    the upload form: copy `[[File:xxx]]` here, where the FINAL file name is
 *    known (the submit-time field may be empty/un-normalized);
 *  - ?wbanother=1 — the "Submit and upload another image from same author"
 *    button was used: this page is the NEW TAB; send the opener (the upload
 *    form) back to a fresh form with the license/author/license-info fields
 *    preserved (they ride wblicense/wbauthor/wblicenseinfo).
 * Both params are one-shot and stripped from the URL afterwards.
 */
( function () {
	'use strict';

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

	function makeButton( id, messageKey, hintKey, handler ) {
		return $( '<button>' )
			.attr( 'id', id )
			.attr( 'type', 'button' )
			.addClass( 'wb-embed-toolbar-btn' )
			.attr( 'title', hintKey ? mw.msg( hintKey ) : '' )
			.text( mw.msg( messageKey ) )
			.on( 'click', handler );
	}

	mw.loader.using( [ 'mediawiki.notification', 'mediawiki.util' ] ).then( function () {
		var name = mw.config.get( 'wbFileName' );
		var url = mw.config.get( 'wbFileUrl' );
		if ( !name || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		var snippet = '[[File:' + name + ']]';

		// Two copy buttons, inline to the right of the title (created once).
		if ( $( '#ca-wb-file-copyembed' ).length === 0 ) {
			var $toolbar = $( '<span class="wb-file-toolbar"></span>' )
				.append( makeButton(
					'ca-wb-file-copyembed',
					'embeddablecontent-file-copyembed',
					'embeddablecontent-file-copyembed-hint',
					function () { copyText( snippet ); }
				) );
			if ( url ) {
				$toolbar.append( makeButton(
					'ca-wb-file-copylink',
					'embeddablecontent-file-copylink',
					'embeddablecontent-file-copylink-hint',
					function () { copyText( url ); }
				) );
			}
			$( '#firstHeading' ).append( $toolbar );
		}

		// Upload hand-off (one-shot params appended by BeforePageRedirect).
		var params = new URLSearchParams( window.location.search );
		if ( params.get( 'wbuploadcopy' ) === '1' ) {
			copyText( snippet );
		}
		if ( params.get( 'wbanother' ) === '1' && window.opener && !window.opener.closed ) {
			var query = {};
			[ [ 'wblicense', 'wpLicense' ], [ 'wbauthor', 'wpUploadAuthor' ], [ 'wblicenseinfo', 'wpUploadLicenseInfo' ] ]
				.forEach( function ( pair ) {
					var value = params.get( pair[ 0 ] );
					if ( value ) {
						query[ pair[ 1 ] ] = value;
					}
				} );
			try {
				window.opener.location.href = mw.util.getUrl( 'Special:Upload', query );
			} catch ( e ) {
				// Cross-origin opener (never on this instance) — ignore.
			}
		}
		if ( params.get( 'wbuploadcopy' ) === '1' || params.get( 'wbanother' ) === '1' ) {
			[ 'wbuploadcopy', 'wbanother', 'wblicense', 'wbauthor', 'wblicenseinfo' ]
				.forEach( function ( key ) { params.delete( key ); } );
			var clean = window.location.pathname + ( params.toString() ? '?' + params.toString() : '' );
			window.history.replaceState( null, '', clean );
		}
	} );
}() );
