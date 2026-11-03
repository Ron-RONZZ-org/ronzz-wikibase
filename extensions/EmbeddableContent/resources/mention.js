/**
 * "Copy internal mention" button on the classic per-kind pages
 * (Source: / Person: / Collective: / FOSS: / Software:). It copies a plain
 * internal link to the page, `[[<page>]]` — the smart mention renderer
 * (Hooks::onHtmlPageLinkRendererBegin) strips the namespace and italicises
 * Source: links, so no piped label is needed.
 *
 * The server resolves the page title into wbMentionLink
 * (Hooks::wireMentionButton). The button joins the SHARED .wb-embed-toolbar
 * row under the page title; on source pages it is placed to the RIGHT of the
 * "Copy internal citation" button (this module is loaded after sourcecite, so
 * the citation button already exists).
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

	mw.loader.using( 'mediawiki.notification' ).then( function () {
		var link = mw.config.get( 'wbMentionLink' );
		if ( !link || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		if ( $( '#ca-wb-mention' ).length > 0 ) {
			return;
		}
		var snippet = '[[' + link + ']]';
		var $button = $( '<button>' )
			.attr( 'id', 'ca-wb-mention' )
			.attr( 'type', 'button' )
			.addClass( 'wb-embed-toolbar-btn' )
			.attr( 'title', mw.msg( 'embeddablecontent-mention-hint' ) )
			.text( mw.msg( 'embeddablecontent-mention-button' ) )
			.on( 'click', function () {
				copyText( snippet );
			} );

		// Ranked by the shared toolbar primitive: after "Copy internal
		// citation" (when present), before "Copy embed code".
		mw.embeddableContent.toolbar.add(
			mw.embeddableContent.toolbar.getRow(),
			$button,
			mw.embeddableContent.toolbar.ORDER.mention
		);
	} );
}() );
