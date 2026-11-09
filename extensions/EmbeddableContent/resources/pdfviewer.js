/**
 * Browser-native PDF viewer with a fullscreen control.
 *
 * No third-party PDF library: the PDF renders in a same-origin iframe using
 * the browser's built-in viewer (the same mechanism Special:SourceFile has
 * always used). This module only adds the toolbar (fullscreen + open in a
 * new tab) around it.
 *
 * Used by:
 *  - the File: page (resources/filepage.js builds the container for a PDF
 *    upload and calls attach() after inserting it);
 *  - Special:SourceFile (server-rendered container; the module auto-wires).
 */
( function () {
	'use strict';

	function update( $container, $button ) {
		var full = document.fullscreenElement === $container[ 0 ]
			|| document.webkitFullscreenElement === $container[ 0 ];
		$button.text( mw.msg( full
			? 'embeddablecontent-pdf-fullscreen-exit'
			: 'embeddablecontent-pdf-fullscreen' ) );
		$container.toggleClass( 'wb-pdf-fullscreen', full );
	}

	function attach( $container ) {
		if ( !$container || !$container.length || $container.data( 'wbPdfWired' ) ) {
			return;
		}
		$container.data( 'wbPdfWired', true );
		var frame = $container.find( 'iframe' ).first();
		if ( !frame.length ) {
			return;
		}
		var $toolbar = $container.find( '.wb-pdf-toolbar' ).first();
		if ( !$toolbar.length ) {
			$toolbar = $( '<div class="wb-pdf-toolbar"></div>' );
			$container.prepend( $toolbar );
		}
		var $button = $( '<button type="button" class="wb-pdf-fullscreen-btn"></button>' );
		var $open = $( '<a class="wb-pdf-newtab" target="_blank" rel="noopener"></a>' )
			.attr( 'href', frame.attr( 'src' ) )
			.text( mw.msg( 'embeddablecontent-pdf-newtab' ) );
		$toolbar.append( $button, $open );

		function toggle() {
			var el = $container[ 0 ];
			if ( document.fullscreenElement === el || document.webkitFullscreenElement === el ) {
				if ( document.exitFullscreen ) {
					document.exitFullscreen();
				} else if ( document.webkitExitFullscreen ) {
					document.webkitExitFullscreen();
				}
				return;
			}
			if ( el.requestFullscreen ) {
				el.requestFullscreen();
			} else if ( el.webkitRequestFullscreen ) {
				el.webkitRequestFullscreen();
			}
		}

		$button.on( 'click', toggle );
		document.addEventListener( 'fullscreenchange', function () {
			update( $container, $button );
		} );
		document.addEventListener( 'webkitfullscreenchange', function () {
			update( $container, $button );
		} );
		update( $container, $button );
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.pdfviewer = { attach: attach };

	mw.loader.using( [ 'mediawiki.util' ] ).then( function () {
		$( '.wb-pdf-viewer' ).each( function () {
			attach( $( this ) );
		} );
	} );
}() );
