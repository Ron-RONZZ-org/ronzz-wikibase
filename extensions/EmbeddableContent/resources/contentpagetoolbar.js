/**
 * Classic content-page action toolbar, rendered INLINE to the right of the
 * page title (the File: page copy-button pattern). Its actions:
 *
 *   - "Copy internal mention" — copies `[[Page name]]` so an editor can link
 *     to this page from anywhere on the wiki (the snippet is resolved by
 *     Hooks + Spec\MentionSnippet into wbReferenceSnippet);
 *   - "Copy citation" — opens the shared citation popup
 *     (resources/citationpopup.js) for the PAGE itself (wbCitePage): its
 *     title, canonical URL and last-revision date, formatted in APA /
 *     Vancouver / BibTeX / RIS.
 *
 * The module is deliberately generic (a content-page action toolbar): future
 * page-level actions join the same inline row.
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

	mw.loader.using( [ 'mediawiki.notification', 'mediawiki.util' ] ).then( function () {
		var snippet = mw.config.get( 'wbReferenceSnippet' );
		var citePage = mw.config.get( 'wbCitePage' );
		if ( ( !snippet && !citePage ) || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		if ( $( '#ca-wb-content-copymention' ).length > 0 || $( '#ca-wb-content-copycite' ).length > 0 ) {
			return;
		}
		// Reuse the inline page-toolbar row when it already exists — print.js
		// loads first and creates it (its "Print this page" button shares this
		// row); otherwise create it.
		var $toolbar = $( '.wb-content-page-toolbar' ).first();
		if ( $toolbar.length === 0 ) {
			$toolbar = $( '<span class="wb-content-page-toolbar"></span>' );
			$( '#firstHeading' ).append( $toolbar );
		}

		if ( snippet ) {
			$toolbar.append(
				$( '<button>' )
					.attr( 'id', 'ca-wb-content-copymention' )
					.attr( 'type', 'button' )
					.addClass( 'wb-embed-toolbar-btn' )
					.attr( 'title', mw.msg( 'embeddablecontent-contentpage-copymention-hint' ) )
					.text( mw.msg( 'embeddablecontent-contentpage-copymention' ) )
					.on( 'click', function () { copyText( snippet ); } )
			);
		}

		if ( citePage ) {
			var $cite = $( '<button>' )
				.attr( 'id', 'ca-wb-content-copycite' )
				.attr( 'type', 'button' )
				.addClass( 'wb-embed-toolbar-btn' )
				.attr( 'title', mw.msg( 'embeddablecontent-contentpage-copycite-hint' ) )
				.text( mw.msg( 'embeddablecontent-gadget-copycitation' ) )
				.on( 'click', function () {
					mw.embeddableContent.citationPopup.open( $cite, { page: citePage } );
				} );
			$toolbar.append( $cite );
		}
	} );
}() );
