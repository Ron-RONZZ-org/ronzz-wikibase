/** Special:SourceText — copy the extracted Markdown. */
( function () {
	'use strict';
	mw.loader.using( [ 'mediawiki.notification', 'mediawiki.util' ] ).then( function () {
		var pre = document.querySelector( '.wb-source-text' );
		var button = document.querySelector( '.wb-source-text-copy' );
		if ( !pre || !button ) {
			return;
		}
		button.addEventListener( 'click', function () {
			var text = pre.textContent || '';
			var notify = function () {
				mw.notify( mw.msg( 'embeddablecontent-gadget-copied' ) );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( notify, function () {
					mw.notify( mw.msg( 'embeddablecontent-source-text-copy-failed' ), { type: 'warn' } );
				} );
			} else {
				mw.notify( mw.msg( 'embeddablecontent-source-text-copy-failed' ), { type: 'warn' } );
			}
		} );
	} );
}() );
