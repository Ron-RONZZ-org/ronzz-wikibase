/**
 * "Copy internal citation" button on Source: classic pages — copies the
 * wikitext snippet `<ref>{{#cite:Q42}}</ref>` so an editor can cite the
 * page's source item on any wiki page (the {{#cite:}} parser function
 * inside a stock-Cite <ref>).
 *
 * The server resolves the page → item id (site-link store) and sets
 * wbInternalCiteItem (Hooks::onBeforePageDisplay, NS_SOURCE branch); this
 * module renders ONE button into the shared `.wb-embed-toolbar` row under
 * the page title — the exact row + button styles the entity-page gadget
 * and the "Update basic information" button use (gadget.css), so the
 * Source: page action surface matches the Item: page one.
 */
( function () {
	'use strict';

	/**
	 * The shared toolbar row under the page title: created on first use
	 * (the gadget's own getToolbar does not run on Source pages — they are
	 * not entity pages), styled by gadget.css.
	 */
	function getToolbar() {
		var $toolbar = $( '.wb-embed-toolbar' );
		if ( $toolbar.length === 0 ) {
			$toolbar = $( '<div class="wb-embed-toolbar"></div>' );
			$( '#firstHeading' ).after( $toolbar );
		}
		return $toolbar;
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

	mw.loader.using( 'mediawiki.notification' ).then( function () {
		var qid = mw.config.get( 'wbInternalCiteItem' );
		if ( !qid || !/^Q[1-9]\d*$/.test( qid ) || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		var snippet = '<ref>{{#cite:' + qid + '}}</ref>';
		getToolbar().append(
			$( '<button>' )
				.attr( 'id', 'ca-wb-source-cite-internal' )
				.attr( 'type', 'button' )
				.addClass( 'wb-embed-toolbar-btn' )
				.attr( 'title', mw.msg( 'embeddablecontent-sourcecite-hint', qid ) )
				.text( mw.msg( 'embeddablecontent-sourcecite-button' ) )
				.on( 'click', function () {
					copyText( snippet );
				} )
		);
	} );
}() );
