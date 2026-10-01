/**
 * Classic content-page action toolbar, rendered INLINE to the right of the
 * page title (the File: page copy-button pattern). Its first action is the
 * "Copy internal reference" button: it copies `[[Page name]]` so an editor
 * can link to this page from anywhere on the wiki.
 *
 * The module is deliberately generic (a content-page action toolbar): future
 * page-level actions join the same inline row.
 *
 * The server resolves the page's prefixed title into wbReferencePageName
 * (Hooks::onBeforePageDisplay), so the module needs no API roundtrip.
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

	mw.loader.using( [ 'mediawiki.notification', 'mediawiki.util' ] ).then( function () {
		var name = mw.config.get( 'wbReferencePageName' );
		if ( !name || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		if ( $( '#ca-wb-content-copyref' ).length > 0 ) {
			return;
		}
		var snippet = '[[' + name + ']]';
		var $toolbar = $( '<span class="wb-content-page-toolbar"></span>' )
			.append( makeButton(
				'ca-wb-content-copyref',
				'embeddablecontent-contentpage-copyref',
				'embeddablecontent-contentpage-copyref-hint',
				function () { copyText( snippet ); }
			) );
		$( '#firstHeading' ).append( $toolbar );
	} );
}() );
