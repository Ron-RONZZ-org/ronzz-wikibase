/**
 * "Open in a new tab" for files embedded on content pages.
 *
 * The server resolves every file used by the page (ParserOutput::getImages())
 * into wbEmbeddedFiles — { name, url, page } — and loads this module from
 * Hooks::onParserAfterTidy. The module adds a small noprint link to the raw
 * media next to each embed:
 *   - figures get it appended to the figcaption (next to the credit);
 *   - plain images/audio/video players get it after the element;
 *   - galleries get ONE paragraph after the gallery for all their images;
 *   - PDF/EPUB/DJVU link embeds get it after the link.
 *
 * Skipped: infobox galleries (.wb-image-gallery) and anything inside
 * .wb-infobox — a compact data summary, not a content figure.
 */
( function () {
	'use strict';

	/** URL/DBkey → comparable form (spaces and underscores collapse). */
	function normalize( value ) {
		var text = String( value || '' );
		try {
			text = decodeURIComponent( text );
		} catch ( e ) {
			// Leave the raw text; a malformed URL simply won't match.
		}
		return text.replace( /_/g, ' ' ).toLowerCase();
	}

	/** The wbEmbeddedFiles entry whose name occurs in `url`, or null. */
	function fileForUrl( url, files ) {
		var normalized = normalize( url );
		if ( normalized === '' ) {
			return null;
		}
		// Longest name first: a short name that is a prefix of another must
		// not win.
		for ( var i = 0; i < files.length; i++ ) {
			var name = normalize( files[ i ].name );
			if ( name !== '' && normalized.indexOf( name ) !== -1 ) {
				return files[ i ];
			}
		}
		return null;
	}

	function openLink( file ) {
		return $( '<a>' )
			.attr( {
				href: file.url,
				target: '_blank',
				rel: 'noopener'
			} )
			.addClass( 'wb-media-open-link' )
			.attr( 'title', mw.msg( 'embeddablecontent-file-open-newtab' ) )
			.text( mw.msg( 'embeddablecontent-file-open-newtab' ) );
	}

	function openSpan( file ) {
		return $( '<span>' )
			.addClass( 'wb-media-open noprint' )
			.append( ' · ' )
			.append( openLink( file ) );
	}

	/** Ensure the link is added once per embed, after `$target`. */
	function placeAfter( $target, file ) {
		if ( $target.next( '.wb-media-open' ).length ) {
			return;
		}
		$target.after( openSpan( file ) );
	}

	/** Append the link to a figure caption (the credit lives there too). */
	function placeInCaption( $caption, file ) {
		if ( $caption.find( '.wb-media-open' ).length ) {
			return;
		}
		$caption.append( openSpan( file ) );
	}

	function inExcludedContainer( element ) {
		return $( element ).closest( '.wb-infobox, .wb-image-gallery, .gallery' ).length > 0;
	}

	function addGalleryLink( $gallery, files ) {
		var seen = {};
		var links = [];
		$gallery.find( 'img' ).each( function () {
			var file = fileForUrl( this.getAttribute( 'src' ), files );
			if ( file && !seen[ file.name ] ) {
				seen[ file.name ] = true;
				links.push( openLink( file ) );
			}
		} );
		if ( links.length === 0 || $gallery.next( '.wb-media-open-gallery' ).length ) {
			return;
		}
		var $paragraph = $( '<div>' )
			.addClass( 'wb-media-open-gallery noprint' )
			.append( mw.msg( 'embeddablecontent-file-open-newtab' ) + ': ' );
		links.forEach( function ( $link, index ) {
			if ( index > 0 ) {
				$paragraph.append( ' · ' );
			}
			$paragraph.append( $link );
		} );
		$gallery.after( $paragraph );
	}

	mw.loader.using( [ 'mediawiki.util' ] ).then( function () {
		var files = mw.config.get( 'wbEmbeddedFiles' ) || [];
		if ( !files.length ) {
			return;
		}
		// Longest file name first (see fileForUrl).
		files = files.slice().sort( function ( a, b ) {
			return String( b.name ).length - String( a.name ).length;
		} );

		// Galleries: ONE paragraph for all their images, not one per thumb.
		$( '#mw-content-text .gallery' ).each( function () {
			if ( $( this ).closest( '.wb-infobox' ).length === 0 ) {
				addGalleryLink( $( this ), files );
			}
		} );

		// Images (figures/captions and plain images).
		$( '#mw-content-text img' ).each( function () {
			if ( inExcludedContainer( this ) ) {
				return;
			}
			var file = fileForUrl( this.getAttribute( 'src' ), files );
			if ( !file ) {
				return;
			}
			var $figure = $( this ).closest( 'figure' );
			var $caption = $figure.find( 'figcaption' ).first();
			if ( $caption.length ) {
				placeInCaption( $caption, file );
				return;
			}
			var $anchor = $( this ).closest( 'a' );
			placeAfter( $anchor.length ? $anchor : $( this ), file );
		} );

		// Audio / video players.
		$( '#mw-content-text audio, #mw-content-text video' ).each( function () {
			if ( inExcludedContainer( this ) ) {
				return;
			}
			var source = this.getAttribute( 'src' )
				|| $( this ).find( 'source' ).first().attr( 'src' )
				|| '';
			var file = fileForUrl( source, files );
			if ( file ) {
				placeAfter( $( this ), file );
			}
		} );

		// PDF/EPUB/DJVU link embeds (no media handler): one link after the
		// anchor. Only extension-bearing File: links, never ordinary inline
		// links to a File page.
		$( '#mw-content-text a[href*="/wiki/File:"]' ).each( function () {
			var href = this.getAttribute( 'href' ) || '';
			if ( !/\.(pdf|epub|djvu)(?:$|[?#])/i.test( href ) ) {
				return;
			}
			if ( $( this ).closest( '.wb-infobox, .gallery' ).length > 0
				|| $( this ).find( 'img, audio, video' ).length > 0
			) {
				return;
			}
			var file = fileForUrl( href, files );
			if ( file ) {
				placeAfter( $( this ), file );
			}
		} );
	} );
}() );
