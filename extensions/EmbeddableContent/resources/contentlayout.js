/**
 * Content-page layout enhancements.
 *
 *  - Wraps a wikitable in `.wb-table-block` + `.wb-table-scroll` so a wide
 *    table fits the content column and scrolls horizontally instead of
 *    overflowing it.
 *  - Constrains a thumbnail/frame to its media's width. A long caption (or
 *    the image credit line) otherwise sets the figure's max-content width —
 *    a 250 px video rendered in a 542 px figure with a large empty side
 *    margin (the Solid oxide fuel cell report).
 *  - Opens the fullscreen viewer when a content image is clicked. Images are
 *    normally wrapped in a File: link; readers expect the picture, not the
 *    behind-the-scenes description page. Ctrl/Cmd/Shift/middle click still
 *    follows the File: link, and mediaopen.js adds a small "File page" link.
 *  - Native Fullscreen API only — no fixed-position wrapper (the reason the
 *    old PDF fullscreen control was removed, see
 *    docs/decisions/fullscreen-removed-open-newtab.md).
 *
 * The float-clearing rules live in contentlayout.css.
 */
( function () {
	'use strict';

	function supportsFullscreen() {
		return !!( document.fullscreenEnabled || document.webkitFullscreenEnabled );
	}

	function fullscreenElement() {
		return document.fullscreenElement || document.webkitFullscreenElement || null;
	}

	function requestFullscreen( element ) {
		var request = element.requestFullscreen || element.webkitRequestFullscreen;
		if ( !request ) {
			return false;
		}
		try {
			var result = request.call( element );
			if ( result && typeof result.catch === 'function' ) {
				result.catch( function () {} );
			}
			return true;
		} catch ( e ) {
			return false;
		}
	}

	function exitFullscreen() {
		var exit = document.exitFullscreen || document.webkitExitFullscreen;
		if ( exit ) {
			try {
				exit.call( document );
			} catch ( e ) {
				// The browser has already left fullscreen.
			}
		}
	}

	function toggleFullscreen( element ) {
		if ( fullscreenElement() ) {
			exitFullscreen();
			return;
		}
		requestFullscreen( element );
	}

	function onFullscreenChange() {
		$( '.wb-fullscreen-active' ).removeClass( 'wb-fullscreen-active' );
		var active = fullscreenElement();
		if ( active ) {
			$( active ).addClass( 'wb-fullscreen-active' );
			swapToOriginal( active );
		} else {
			restoreOriginals();
		}
	}

	/**
	 * The rendered image is the article thumbnail (often 250 px). Show the
	 * ORIGINAL media in fullscreen; mediaopen.js records its URL on the img.
	 * Restored on exit so the page keeps its thumbnail.
	 */
	function swapToOriginal( element ) {
		var image = null;
		if ( element.tagName === 'IMG' ) {
			image = element;
		} else if ( element.tagName === 'FIGURE' ) {
			image = element.querySelector( 'img' );
		}
		var url = image && image.getAttribute( 'data-wb-media-url' );
		if ( !url ) {
			return;
		}
		image.setAttribute( 'data-wb-original-src', image.getAttribute( 'src' ) || '' );
		var srcset = image.getAttribute( 'srcset' );
		if ( srcset ) {
			image.setAttribute( 'data-wb-original-srcset', srcset );
		}
		image.removeAttribute( 'srcset' );
		image.removeAttribute( 'sizes' );
		image.setAttribute( 'src', url );
	}

	function restoreOriginals() {
		$( 'img[data-wb-original-src]' ).each( function () {
			this.setAttribute( 'src', this.getAttribute( 'data-wb-original-src' ) );
			var srcset = this.getAttribute( 'data-wb-original-srcset' );
			if ( srcset ) {
				this.setAttribute( 'srcset', srcset );
			}
			this.removeAttribute( 'data-wb-original-src' );
			this.removeAttribute( 'data-wb-original-srcset' );
		} );
	}

	/** Mark the element whose fullscreen state is toggling, for the CSS. */
	function marks( element ) {
		$( element ).addClass( 'wb-fullscreen-target' );
	}

	/**
	 * Wrap `table` in `.wb-table-block > .wb-table-tools + .wb-table-scroll`
	 * and return the block. Idempotent.
	 *
	 * @param {HTMLTableElement} table
	 * @return {jQuery}
	 */
	function wrapTable( table ) {
		var $table = $( table );
		var $block = $table.closest( '.wb-table-block' );
		if ( $block.length ) {
			return $block;
		}
		var $scroll = $( '<div>' ).addClass( 'wb-table-scroll' );
		var $tools = $( '<div>' ).addClass( 'wb-table-tools noprint' );
		$block = $( '<div>' ).addClass( 'wb-table-block' );
		$table.before( $block );
		$scroll.append( $table );
		$block.append( $tools ).append( $scroll );

		if ( supportsFullscreen() ) {
			var $button = $( '<button>' )
				.attr( 'type', 'button' )
				.addClass( 'wb-fullscreen-btn' )
				.text( mw.msg( 'embeddablecontent-media-fullscreen' ) );
			$button.on( 'click', function ( event ) {
				event.preventDefault();
				toggleFullscreen( $block[ 0 ] );
			} );
			$tools.append( $button );
			marks( $block[ 0 ] );
		}
		return $block;
	}

	/**
	 * A figure's width is max-content by default: a long caption or credit
	 * line widens it far beyond the media. Pin it to the media's declared
	 * width and let the caption wrap.
	 */
	function constrainFigure( figure ) {
		var $figure = $( figure );
		if ( $figure.data( 'wbWidthConstrained' ) ) {
			return;
		}
		var media = figure.querySelector( 'video, img' );
		if ( !media ) {
			return;
		}
		var width = parseInt( media.getAttribute( 'width' ), 10 );
		if ( !width || width <= 0 ) {
			return;
		}
		$figure.data( 'wbWidthConstrained', true );
		figure.style.width = width + 'px';
		figure.style.maxWidth = '100%';
		media.style.maxWidth = '100%';
	}

	/**
	 * A File:-linked content image opens the fullscreen viewer on a plain
	 * click. The link is left in place for modified/middle clicks (new tab)
	 * and keyboard users; mediaopen.js adds the visible File-page fallback.
	 */
	function wireImage( $anchor ) {
		if ( $anchor.data( 'wbFullscreenWired' ) ) {
			return;
		}
		var $image = $anchor.find( 'img' ).first();
		if ( !$image.length ) {
			return;
		}
		if ( $anchor.closest( '.wb-infobox, .wb-image-gallery' ).length ) {
			return;
		}
		// `[[File:X|link=Article]]` keeps its article link: intercept only a
		// File: description link.
		var href = $anchor.attr( 'href' ) || '';
		if ( !/(?:^|\/)wiki\/File:/.test( href ) && !/[?&]title=File:/.test( href ) ) {
			return;
		}
		$anchor.data( 'wbFullscreenWired', true );
		$anchor.addClass( 'wb-media-zoom' );
		if ( !$anchor.attr( 'title' ) ) {
			$anchor.attr( 'title', mw.msg( 'embeddablecontent-media-fullscreen-hint' ) );
		}
		$anchor.on( 'click', function ( event ) {
			if ( event.which !== 1 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) {
				return;
			}
			if ( !supportsFullscreen() ) {
				return;
			}
			var $figure = $anchor.closest( 'figure' );
			var target = $figure.length ? $figure[ 0 ] : $image[ 0 ];
			marks( target );
			event.preventDefault();
			toggleFullscreen( target );
		} );
	}

	function init() {
		$( '#mw-content-text table.wikitable' ).each( function () {
			if ( $( this ).closest( '.wb-infobox' ).length === 0 ) {
				wrapTable( this );
			}
		} );
		$( '#mw-content-text figure[typeof~="mw:File/Thumb"], #mw-content-text figure[typeof~="mw:File/Frame"]' ).each( function () {
			constrainFigure( this );
		} );
		$( '#mw-content-text a.mw-file-description' ).each( function () {
			wireImage( $( this ) );
		} );
		$( document ).on( 'fullscreenchange webkitfullscreenchange', onFullscreenChange );
	}

	// Set synchronously: mediaopen.js depends on this module and calls
	// wrapTable() before its own async pass runs.
	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.contentLayout = {
		wrapTable: wrapTable,
		toggleFullscreen: toggleFullscreen
	};

	mw.loader.using( [ 'mediawiki.util' ] ).then( function () {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', init );
		} else {
			init();
		}
	} );
}() );
