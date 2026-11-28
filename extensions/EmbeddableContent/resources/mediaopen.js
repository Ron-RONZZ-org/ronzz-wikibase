/**
 * Content-page media affordances.
 *
 * The server resolves every file used by the page (ParserOutput::getImages())
 * into wbEmbeddedFiles — { name, url, page, credit, tableCredit } — and loads
 * this module from Hooks::onParserAfterTidy. The module adds:
 *   - a small noprint "Open in a new tab" link to the raw media next to each
 *     embed (captionless images/audio/video/PDF links; galleries get ONE
 *     paragraph for all their images);
 *   - a noprint "File page" fallback (content images now open the fullscreen
 *     viewer on a plain click — see resources/contentlayout.js);
 *   - the automatic credit for captionless embeds;
 *   - for a WIKITABLE, the credits are moved out of the cells into ONE
 *     paragraph below the table (Image: file · author · source · licence).
 *     A credit line in an 80 px cell otherwise widens the table far beyond
 *     the content column and misaligns every row.
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
		if ( file.internal === '1' && mw.user.isAnon() ) {
			return null;
		}
		return $( '<span>' )
			.addClass( 'wb-media-open noprint' )
			.append( ' · ' )
			.append( openLink( file ) );
	}

	function creditSpan( file ) {
		if ( !file.credit ) {
			return null;
		}
		return $( '<span>' )
			.addClass( 'wb-image-credit wb-image-credit-inline' )
			.attr( 'data-wb-file', file.name )
			.html( file.credit );
	}

	function detailsSpan( file ) {
		if ( !file.page ) {
			return null;
		}
		if ( file.internal === '1' && mw.user.isAnon() ) {
			return null;
		}
		return $( '<span>' )
			.addClass( 'wb-media-details noprint' )
			.append( ' · ' )
			.append(
				$( '<a>' )
					.attr( {
						href: mw.util.getUrl( file.page ),
						title: mw.msg( 'embeddablecontent-file-details-hint' )
					} )
					.addClass( 'wb-media-details-link' )
					.text( mw.msg( 'embeddablecontent-file-details' ) )
			);
	}

	function isNoCredit( element ) {
		if ( !element ) {
			return false;
		}
		if ( element.classList && element.classList.contains( 'noCredit' ) ) {
			return true;
		}
		return $( element ).closest( '.wb-no-credit' ).length > 0;
	}

	/** Ensure the credit/open link is added once per captionless embed. */
	function placeAfter( $target, file, noCredit ) {
		if ( $target.next( '.wb-media-tools' ).length ) {
			return;
		}
		var $tools = $( '<span>' ).addClass( 'wb-media-tools' );
		if ( !noCredit ) {
			var $credit = creditSpan( file );
			if ( $credit ) {
				$tools.append( $credit );
			}
		}
		var $open = openSpan( file );
		if ( $open ) {
			$tools.append( $open );
		}
		var $details = detailsSpan( file );
		if ( $details ) {
			$tools.append( $details );
		}
		if ( $tools.children().length ) {
			$target.after( $tools );
		}
	}

	/** Append the credit/open link to a figure caption. Thumbnails/frames
	 * already carry the server-rendered link (and usually the credit) —
	 * Media\FileOpenLink writes a bare `.wb-media-open`, NOT wrapped in
	 * `.wb-media-tools`, so both markers must suppress the client copy or the
	 * caption shows "Open in a new tab" twice. The `data-wb-file` marker is
	 * still recorded: mediaopen.js moves a table's credits out of the cells. */
	function placeInCaption( $caption, file, noCredit ) {
		var $existingCredit = $caption.find( '.wb-image-credit' ).first();
		if ( $existingCredit.length && !$existingCredit.attr( 'data-wb-file' ) ) {
			$existingCredit.attr( 'data-wb-file', file.name );
		}
		if ( $caption.find( '.wb-media-tools, .wb-media-open' ).length ) {
			if ( $caption.find( '.wb-media-details' ).length === 0 ) {
				var $serverDetails = detailsSpan( file );
				if ( $serverDetails ) {
					$caption.append( $serverDetails );
				}
			}
			return;
		}
		var $tools = $( '<span>' ).addClass( 'wb-media-tools' );
		if ( !noCredit && $caption.find( '.wb-image-credit' ).length === 0 ) {
			var $credit = creditSpan( file );
			if ( $credit ) {
				$tools.append( $credit );
			}
		}
		var $open = openSpan( file );
		if ( $open ) {
			$tools.append( $open );
		}
		var $details = detailsSpan( file );
		if ( $details ) {
			$tools.append( $details );
		}
		if ( $tools.children().length ) {
			$caption.append( $tools );
		}
	}

	function inExcludedContainer( element ) {
		return $( element ).closest( '.wb-infobox, .wb-image-gallery, .gallery' ).length > 0;
	}

	function addGalleryLink( $gallery, files ) {
		var seen = {};
		var parts = [];
		$gallery.find( 'img' ).each( function () {
			var file = fileForUrl( this.getAttribute( 'src' ), files );
			if ( !file || seen[ file.name ] ) {
				return;
			}
			$( this ).attr( 'data-wb-media-url', file.url );
			seen[ file.name ] = true;
			var $part = $( '<span>' ).addClass( 'wb-gallery-credit-item' );
			if ( file.credit ) {
				$part.append(
					$( '<span>' )
						.addClass( 'wb-image-credit' )
						.attr( 'data-wb-file', file.name )
						.html( file.credit )
				);
			}
			var $open = openSpan( file );
			if ( $open ) {
				$part.append( $open );
			}
			var $details = detailsSpan( file );
			if ( $details ) {
				$part.append( $details );
			}
			if ( $part.children().length ) {
				parts.push( $part );
			}
		} );
		if ( parts.length === 0 || $gallery.next( '.wb-media-open-gallery' ).length ) {
			return;
		}
		var $paragraph = $( '<div>' ).addClass( 'wb-media-open-gallery noprint' );
		parts.forEach( function ( $part, index ) {
			if ( index > 0 ) {
				$paragraph.append( ' · ' );
			}
			$paragraph.append( $part );
		} );
		$gallery.after( $paragraph );
	}

	/** The wbEmbeddedFiles entry whose name matches `name`, or null. */
	function fileByName( name, files ) {
		var wanted = normalize( name );
		if ( wanted === '' ) {
			return null;
		}
		for ( var i = 0; i < files.length; i++ ) {
			if ( normalize( files[ i ].name ) === wanted ) {
				return files[ i ];
			}
		}
		return null;
	}

	/** The file a credit element belongs to: its data marker first, then the
	 * nearest image/video in the same cell or figure. */
	function fileForCredit( $credit, files ) {
		var direct = fileByName( $credit.attr( 'data-wb-file' ), files );
		if ( direct ) {
			return direct;
		}
		var $container = $credit.closest( 'figure, td, th, li' );
		if ( !$container.length ) {
			$container = $credit.parent();
		}
		var media = $container.find( 'img, video' ).first();
		var source = media.attr( 'src' )
			|| media.find( 'source' ).first().attr( 'src' )
			|| '';
		return fileForUrl( source, files );
	}

	/** Drop a `.wb-media-tools` wrapper the credit move left empty. */
	function removeCredit( $credit ) {
		var $tools = $credit.closest( '.wb-media-tools' );
		$credit.remove();
		if ( $tools.length && $tools.children().length === 0 ) {
			$tools.remove();
		}
	}

	/**
	 * A wikitable's image credits are the table's tall-cell / overflow
	 * problem: a long credit line in an 80 px cell widens the table to
	 * 1432 px in a 752 px column. Move every credit out of the cells into
	 * ONE paragraph below the table (file · author · source · licence), one
	 * line per file. The `.wb-media-open`/details links stay inline.
	 */
	function relocateTableCredits( files ) {
		$( '#mw-content-text table.wikitable' ).each( function () {
			var $table = $( this );
			if ( $table.closest( '.wb-infobox' ).length ) {
				return;
			}
			var credits = $table.find( '.wb-image-credit' );
			if ( !credits.length ) {
				return;
			}
			var seen = {};
			var parts = [];
			credits.each( function () {
				var $credit = $( this );
				var file = fileForCredit( $credit, files );
				if ( !file ) {
					// No map entry: leave the credit where it is rather than
					// deleting an attribution we cannot rebuild.
					return;
				}
				var line = file.tableCredit || file.credit || '';
				if ( seen[ file.name ] || !line ) {
					removeCredit( $credit );
					return;
				}
				seen[ file.name ] = true;
				parts.push( $( '<span>' ).addClass( 'wb-table-credit-item' ).html( line ) );
				removeCredit( $credit );
			} );
			if ( parts.length === 0 ) {
				return;
			}
			var $block = mw.embeddableContent.contentLayout.wrapTable( $table[ 0 ] );
			if ( $block.next( '.wb-table-credits' ).length ) {
				return;
			}
			var $paragraph = $( '<p>' );
			parts.forEach( function ( $part, index ) {
				if ( index > 0 ) {
					$paragraph.append( '<br>' );
				}
				$paragraph.append( $part );
			} );
			$block.after( $( '<div>' ).addClass( 'wb-table-credits' ).append( $paragraph ) );
		} );
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
			// The fullscreen viewer swaps the (possibly 250 px) thumbnail for
			// the original media; resources/contentlayout.js reads this.
			$( this ).attr( 'data-wb-media-url', file.url );
			var noCredit = isNoCredit( this );
			var $figure = $( this ).closest( 'figure' );
			var $caption = $figure.find( 'figcaption' ).first();
			if ( $caption.length ) {
				placeInCaption( $caption, file, noCredit );
				return;
			}
			var $anchor = $( this ).closest( 'a' );
			placeAfter( $anchor.length ? $anchor : $( this ), file, noCredit );
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
				placeAfter( $( this ), file, false );
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
				placeAfter( $( this ), file, false );
			}
		} );

		// One paragraph after each wikitable instead of a per-cell credit.
		relocateTableCredits( files );
	} );
}() );
