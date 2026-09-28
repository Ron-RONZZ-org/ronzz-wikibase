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
 *
 * BROWSER CLIPBOARD LIMIT: a page-load clipboard write is not allowed without
 * a user gesture — Firefox rejects it outright ("lack of user activation")
 * and Chromium only auto-grants it to a focused tab (so the "upload another"
 * NEW-TAB destination is blocked too). The auto-copy is therefore
 * best-effort; when it fails (or is not permitted) a persistent one-click
 * "copy" notice is rendered instead, so the code is always one gesture away.
 */
( function () {
	'use strict';

	/** Copy `text`, resolving true on success and false on failure. */
	function copyText( text ) {
		return new Promise( function ( resolve ) {
			var done = function ( ok ) {
				if ( ok ) {
					mw.notify( mw.msg( 'embeddablecontent-gadget-copied' ) );
				}
				resolve( ok );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then(
					function () { done( true ); },
					function () { done( fallbackCopy( text ) ); }
				);
				return;
			}
			done( fallbackCopy( text ) );
		} );
	}

	/** @return {boolean} whether the legacy copy command reported success */
	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		var ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		} finally {
			document.body.removeChild( ta );
		}
		return ok;
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

	/**
	 * Persistent fallback shown when the automatic clipboard write is blocked:
	 * the snippet plus a copy button that works on the user's click.
	 */
	function showCopyNotice( snippet ) {
		if ( $( '#wb-uploadcopy-notice' ).length > 0 ) {
			return;
		}
		var $notice = $( '<div>' )
			.attr( 'id', 'wb-uploadcopy-notice' )
			.addClass( 'wb-uploadcopy-notice' )
			.append( $( '<p>' ).text( mw.msg( 'embeddablecontent-upload-copyembed-notice' ) ) )
			.append( $( '<code>' ).text( snippet ) )
			.append( ' ' )
			.append( makeButton(
				'wb-uploadcopy-copy',
				'embeddablecontent-file-copyembed',
				null,
				function () { copyText( snippet ); }
			) );
		$( '#mw-content-text' ).first().prepend( $notice );
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
			// Best-effort automatic copy; a blocked write falls back to a
			// visible one-click notice (no gesture on a fresh page load).
			copyText( snippet ).then( function ( ok ) {
				if ( !ok ) {
					showCopyNotice( snippet );
				}
			} );
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
