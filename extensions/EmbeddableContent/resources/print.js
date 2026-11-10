/**
 * Page download / printable-version enhancements. Loaded on every existing
 * article page (Hooks::onBeforePageDisplay sets wbPrintTitle =
 * Title::getSubpageText() — the namespace and the subpage parent are
 * dropped, e.g. "User:Rongzhou/Construction site visit report" prints as
 * "Construction site visit report", "Source:L111-1 of Code de
 * l'éducation (Legislation)" as "L111-1 of Code de l'éducation
 * (Legislation)").
 *
 * Two entry points:
 *   - the core sidebar "Printable version" link (`#t-print a`, whose
 *     `javascript:print();` is intercepted in the CAPTURE phase — core's
 *     own `#t-print a` handler calls `window.print()` first otherwise) opens
 *     the PDF flow directly;
 *   - a "Download" button that JOINS the page's existing action toolbar row
 *     (the inline `.wb-content-page-toolbar` on ordinary content pages, else
 *     the shared `.wb-embed-toolbar` row below the title — `wbPrintToolbar`
 *     picks the surface). It opens a menu with three options:
 *       PDF      → the print popup below (cover page + Print; save as PDF);
 *       HTML     → a standalone .html file of the rendered article;
 *       Wikitext → a .wiki file of the raw wikitext expanded through
 *                  action=expandtemplates (`{{#content:Q…}}`, templates,
 *                  parser functions → their expanded wikitext).
 *
 * The PDF popup (the citationpopup.js OOUI PopupWidget pattern) has an
 * "Add a cover page" checkbox + a Print button. On print the module fetches
 * the page's registered, non-bot contributors
 * (action=query&prop=contributors&pcexcludegroup=bot), renders the title
 * and a centered "by A, B, and C" line (and the optional centered cover
 * page, page-break-after), then calls window.print() and cleans up on
 * afterprint.
 *
 * Ctrl-P (without either entry point) keeps the browser default — a print
 * output cannot be retro-fitted after the fact.
 *
 * The browser's Save-as-PDF dialog suggests a filename from document.title.
 * While our print flow runs, document.title is temporarily set to the
 * namespace-stripped page title with spaces and slashes normalised to "_"
 * (e.g. "User:Rongzhou/Construction site visit report" ->
 * "Rongzhou_Construction_site_visit_report.pdf"); it is restored on
 * afterprint. The printed HEADER keeps using wbPrintTitle (the subpage
 * parent is dropped there).
 *
 * Exposes: mw.embeddableContent.print.open( $anchor ) → the Download menu,
 * and mw.embeddableContent.print.print( $anchor ) → the PDF popup.
 */
( function () {
	'use strict';

	var activePopup = null;

	/** The document.title saved before the first print-title swap. */
	var documentTitleBackup = null;

	/**
	 * The Save-as-PDF filename override for the CURRENT print (an archive
	 * page's source title), or null for the normal wgTitle-derived name.
	 */
	var printTitleOverride = null;

	function closeActive() {
		if ( activePopup ) {
			activePopup.$element.remove();
			activePopup = null;
		}
	}

	/**
	 * The suggested download filename for the current page: the
	 * namespace-stripped title (wgTitle, subpage slashes preserved) with
	 * whitespace/slashes and filesystem-illegal characters normalised to
	 * "_". Empty titles yield "".
	 *
	 * @param {string} title
	 * @param {string} extension without the dot
	 * @return {string}
	 */
	function downloadFileName( title, extension ) {
		var name = String( title || '' );
		if ( name.normalize ) {
			name = name.normalize( 'NFC' );
		}
		// Slashes and the characters Windows rejects become "_"; the
		// control-character range covers newlines/tabs a page title should
		// never carry but a copy-pasted title might.
		name = name.replace( /[\/\\:*?"<>|\u0000-\u001F\u007F]+/g, '_' );
		name = name.replace( /\s+/g, '_' );
		name = name.replace( /_+/g, '_' ).replace( /^_+|_+$/g, '' );
		name = name.replace( new RegExp( '\\.' + extension + '$', 'i' ), '' );
		return name === '' ? '' : name + '.' + extension;
	}

	/** The Save-as-PDF suggested filename for the current page. */
	function pdfFileName( title ) {
		return downloadFileName( title, 'pdf' );
	}

	/** The source/title used for non-PDF download filenames and headers. */
	function downloadTitle() {
		var archive = mw.config.get( 'wbPrintArchive' );
		return ( archive && archive.filename ) || mw.config.get( 'wgTitle' ) || '';
	}

	/** Swap document.title for the Save-as-PDF filename (idempotent). */
	function setPrintDocumentTitle() {
		var name = pdfFileName( printTitleOverride || mw.config.get( 'wgTitle' ) );
		if ( name === '' ) {
			return;
		}
		if ( documentTitleBackup === null ) {
			documentTitleBackup = document.title;
		}
		document.title = name;
	}

	/** Restore document.title after the print dialog closes (idempotent). */
	function restoreDocumentTitle() {
		if ( documentTitleBackup !== null ) {
			document.title = documentTitleBackup;
			documentTitleBackup = null;
		}
	}

	/**
	 * "A, B, and C" (Oxford comma, the requested shape) with the reader's
	 * interface language separators ("A, B et C" in French, "A, B kaj C" in
	 * Esperanto). 1 name → "A"; 2 → "A and B".
	 */
	function listAuthors( names ) {
		var and = String( mw.msg( 'and' ) ).trim();
		var comma = String( mw.msg( 'comma-separator' ) );
		if ( names.length <= 1 ) {
			return names[ 0 ] || '';
		}
		if ( names.length === 2 ) {
			return names[ 0 ] + ' ' + and + ' ' + names[ 1 ];
		}
		return names.slice( 0, -1 ).join( comma ) + comma + and + ' ' +
			names[ names.length - 1 ];
	}

	/**
	 * How many revision batches (rvlimit=max, i.e. up to 500 revisions each)
	 * are walked when counting contributors — a safety cap on a very long
	 * page history (up to 5000 revisions counted).
	 */
	var MAX_REVISION_BATCHES = 10;

	/**
	 * Registered, non-bot contributors of the current page, ordered by
	 * revision count (most active first; ties broken alphabetically).
	 *
	 * `prop=contributors` yields the bot-excluded distinct-contributor SET
	 * only — it carries no edit counts and its order is arbitrary — so the
	 * counts come from the page's revision list, walked with the rvcontinue
	 * token (capped at MAX_REVISION_BATCHES batches). Resolves [] on any
	 * failure (the print flow never blocks on it).
	 */
	function fetchAuthors() {
		var api = new mw.Api();
		var title = mw.config.get( 'wgPageName' );
		return api.get( {
			action: 'query',
			prop: 'contributors',
			titles: title,
			pcexcludegroup: 'bot',
			pclimit: 'max',
			formatversion: 2
		} ).then( function ( data ) {
			var page = data && data.query && data.query.pages && data.query.pages[ 0 ];
			var counts = new Map();
			( ( page && page.contributors ) || [] ).forEach( function ( contributor ) {
				// A registered user carries a numeric userid; anonymous edits
				// (and the bot group excluded above) are skipped.
				if ( contributor && contributor.userid && contributor.name ) {
					counts.set( contributor.name, 0 );
				}
			} );
			if ( counts.size === 0 ) {
				return [];
			}
			return countRevisions( api, title, counts ).then( function () {
				return Array.from( counts.keys() ).sort( function ( a, b ) {
					return counts.get( b ) - counts.get( a ) ||
						a.toLowerCase().localeCompare( b.toLowerCase() );
				} );
			} );
		} ).catch( function () {
			return [];
		} );
	}

	/**
	 * Walk the page's revisions (rvcontinue), incrementing counts for the
	 * allowed (registered, non-bot) users only; bail out after
	 * MAX_REVISION_BATCHES batches.
	 */
	function countRevisions( api, title, counts ) {
		var batches = MAX_REVISION_BATCHES;
		function step( rvcontinue ) {
			var params = {
				action: 'query',
				prop: 'revisions',
				titles: title,
				rvprop: 'user',
				rvlimit: 'max',
				formatversion: 2
			};
			if ( rvcontinue ) {
				params.rvcontinue = rvcontinue;
			}
			return api.get( params ).then( function ( data ) {
				var page = data && data.query && data.query.pages && data.query.pages[ 0 ];
				( ( page && page.revisions ) || [] ).forEach( function ( revision ) {
					if ( revision.user !== undefined && counts.has( revision.user ) ) {
						counts.set( revision.user, counts.get( revision.user ) + 1 );
					}
				} );
				var next = data && data.continue && data.continue.rvcontinue;
				if ( next && --batches > 0 ) {
					return step( next );
				}
			} );
		}
		return step( undefined );
	}

	/** Remove any injected print nodes and the printing body class. */
	function cleanup() {
		$( '.wb-print-header, .wb-print-cover' ).remove();
		$( document.body ).removeClass( 'wb-printing' );
		restoreDocumentTitle();
		printTitleOverride = null;
	}

	/** Inject the print-only header (or cover page) and mark the body. */
	function inject( title, authorLine, withCover ) {
		cleanup();
		var $mount = $( '#mw-content-text' ).first();
		if ( $mount.length === 0 ) {
			$mount = $( document.body );
		}
		var $node = $( '<div></div>' ).addClass( withCover ? 'wb-print-cover' : 'wb-print-header' );
		$node.append( $( '<div class="wb-print-title"></div>' ).text( title ) );
		if ( authorLine ) {
			$node.append( $( '<div class="wb-print-authors"></div>' ).text( authorLine ) );
		}
		$mount.prepend( $node );
		$( document.body ).addClass( 'wb-printing' );
	}

	/**
	 * Inject the archive cover: the SOURCE work (title + class) and its
	 * archive provenance. The archive page's contributors are never credited
	 * — the text is external, not wiki-authored.
	 *
	 * @param {{ title: string, class: string, date: string, url: string }} archive
	 */
	function injectArchiveCover( archive ) {
		cleanup();
		var $mount = $( '#mw-content-text' ).first();
		if ( $mount.length === 0 ) {
			$mount = $( document.body );
		}
		var title = archive.title || mw.config.get( 'wbPrintTitle' ) || '';
		if ( archive.class ) {
			title += ' (' + archive.class + ')';
		}
		var $cover = $( '<div class="wb-print-cover wb-print-archive-cover"></div>' );
		$cover.append( $( '<h1 class="wb-print-title"></h1>' ).text( title ) );
		if ( archive.date || archive.url ) {
			var line = archive.url
				? mw.msg( 'embeddablecontent-print-archive-cover', archive.date, archive.url )
				: mw.msg( 'embeddablecontent-print-archive-cover-nourl', archive.date );
			$cover.append( $( '<div class="wb-print-archive-meta"></div>' ).text( line ) );
		}
		$mount.prepend( $cover );
		$( document.body ).addClass( 'wb-printing' );
	}

	/** Fetch the authors, inject the print DOM, then open the print dialog. */
	function doPrint( withCover ) {
		var archive = mw.config.get( 'wbPrintArchive' );
		if ( archive ) {
			if ( withCover ) {
				injectArchiveCover( archive );
			} else {
				// No cover: a centered header naming the source work, still
				// without the wiki contributor line.
				inject( mw.config.get( 'wbPrintTitle' ) || '', '', false );
			}
			// inject() runs cleanup(), which clears the override — set it AFTER.
			printTitleOverride = archive.filename || null;
			setPrintDocumentTitle();
			window.print();
			return;
		}
		fetchAuthors().then( function ( names ) {
			var title = mw.config.get( 'wbPrintTitle' ) || '';
			var authorLine = names.length > 0
				? mw.msg( 'embeddablecontent-print-by', listAuthors( names ) )
				: '';
			inject( title, authorLine, withCover );
			// The Save-as-PDF dialog reads document.title; swap it just
			// before the dialog opens and restore it on afterprint.
			setPrintDocumentTitle();
			window.print();
		} );
	}

	/** Escape a plain string for HTML text/attribute output. */
	function escapeHtml( value ) {
		return $( '<div>' ).text( String( value || '' ) ).html();
	}

	/** Trigger a client-side download for generated text content. */
	function triggerDownload( name, text, mime ) {
		if ( name === '' ) {
			return;
		}
		var blob = new Blob( [ text ], { type: mime } );
		var url = URL.createObjectURL( blob );
		var link = document.createElement( 'a' );
		link.href = url;
		link.download = name;
		link.style.display = 'none';
		document.body.appendChild( link );
		link.click();
		window.setTimeout( function () {
			URL.revokeObjectURL( url );
			link.remove();
		}, 0 );
	}

	function notifyDownloadError( error ) {
		mw.notify(
			mw.msg(
				'embeddablecontent-download-error',
				error && error.message ? error.message : String( error )
			),
			{ type: 'error' }
		);
	}

	/**
	 * Make root-relative URLs absolute in a parsed HTML fragment, so the
	 * downloaded file's links and images still resolve when opened locally
	 * (the fragment carries `/wiki/…` and `/images/…` paths).
	 */
	function absolutizeHtml( html ) {
		var origin = mw.config.get( 'wgServer' ) || window.location.origin;
		var doc = new DOMParser().parseFromString( html, 'text/html' );
		[ 'a[href]', 'img[src]', 'source[src]', 'video[src]', 'audio[src]', 'link[href]' ]
			.forEach( function ( selector ) {
				var attr = selector.indexOf( '[src]' ) !== -1 ? 'src' : 'href';
				doc.querySelectorAll( selector ).forEach( function ( el ) {
					var value = el.getAttribute( attr ) || '';
					if ( value.charAt( 0 ) === '/' && value.charAt( 1 ) !== '/' ) {
						el.setAttribute( attr, origin + value );
					}
				} );
			} );
		return doc.body.innerHTML;
	}

	/** Build a standalone, minimally styled HTML document around the article. */
	function wrapHtmlDocument( fragment, title ) {
		var lang = mw.config.get( 'wgContentLanguage' ) || 'en';
		return '<!DOCTYPE html>\n<html lang="' + escapeHtml( lang ) + '">\n'
			+ '<head>\n<meta charset="utf-8">\n'
			+ '<meta name="viewport" content="width=device-width, initial-scale=1">\n'
			+ '<title>' + escapeHtml( title ) + '</title>\n'
			+ '<style>\n'
			+ 'body{max-width:52em;margin:2em auto;padding:0 1em;'
			+ 'font-family:Georgia,"Times New Roman",serif;line-height:1.6;color:#202122;background:#fff}\n'
			+ 'img,video{max-width:100%;height:auto}\n'
			+ 'table{border-collapse:collapse}th,td{border:1px solid #c8ccd1;padding:.3em .5em;vertical-align:top}\n'
			+ 'pre,code{font-family:monospace;background:#f8f9fa}pre{padding:.8em;overflow:auto}\n'
			+ '</style>\n</head>\n<body>\n'
			+ '<h1>' + escapeHtml( title ) + '</h1>\n'
			+ fragment + '\n</body>\n</html>\n';
	}

	/** Fetch the current revision's rendered article HTML. */
	function fetchRenderedHtml() {
		return new mw.Api().get( {
			action: 'parse',
			page: mw.config.get( 'wgPageName' ),
			prop: 'text',
			formatversion: 2,
			disableeditsection: 1,
			disablelimitreport: 1,
			disabletoc: 1
		} ).then( function ( data ) {
			var html = data && data.parse && data.parse.text;
			if ( typeof html !== 'string' || html === '' ) {
				throw new Error( mw.msg( 'embeddablecontent-download-empty' ) );
			}
			return html;
		} );
	}

	/** Download the article as a standalone rendered HTML file. */
	function downloadHtml() {
		fetchRenderedHtml().then( function ( html ) {
			var title = downloadTitle();
			triggerDownload(
				downloadFileName( title, 'html' ),
				wrapHtmlDocument( absolutizeHtml( html ), title ),
				'text/html;charset=utf-8'
			);
		} ).catch( notifyDownloadError );
	}

	/**
	 * Fetch the raw wikitext and expand it through action=expandtemplates, so
	 * parser functions and transclusions (`{{#content:Q…}}`,
	 * `{{#statements:}}`, templates, …) become their expanded wikitext.
	 */
	function fetchExpandedWikitext() {
		var api = new mw.Api();
		var page = mw.config.get( 'wgPageName' );
		return api.get( {
			action: 'parse',
			page: page,
			prop: 'wikitext',
			formatversion: 2
		} ).then( function ( data ) {
			var raw = data && data.parse && data.parse.wikitext;
			if ( typeof raw !== 'string' || raw === '' ) {
				throw new Error( mw.msg( 'embeddablecontent-download-empty' ) );
			}
			return api.post( {
				action: 'expandtemplates',
				title: page,
				text: raw,
				prop: 'wikitext',
				formatversion: 2
			} );
		} ).then( function ( data ) {
			var expanded = data && data.expandtemplates && data.expandtemplates.wikitext;
			if ( typeof expanded !== 'string' ) {
				throw new Error( mw.msg( 'embeddablecontent-download-empty' ) );
			}
			return expanded;
		} );
	}

	/** Download the article as expanded wikitext. */
	function downloadWikitext() {
		fetchExpandedWikitext().then( function ( wikitext ) {
			triggerDownload(
				downloadFileName( downloadTitle(), 'wiki' ),
				wikitext,
				'text/plain;charset=utf-8'
			);
		} ).catch( notifyDownloadError );
	}

	/** Append a popup to the body and track it as the active one. */
	function showPopup( $anchor, $content, label ) {
		closeActive();
		var popup = new OO.ui.PopupWidget( {
			$content: $content,
			$floatableContainer: $anchor,
			padded: true,
			autoFlip: true,
			head: true,
			label: label
		} );
		popup.on( 'toggle', function ( visible ) {
			if ( !visible ) {
				popup.$element.remove();
				if ( activePopup === popup ) {
					activePopup = null;
				}
			}
		} );
		$( document.body ).append( popup.$element );
		activePopup = popup;
		popup.toggle( true );
	}

	/** Open the print (PDF) popup: cover-page option + Print. */
	function openPrintPopup( $anchor ) {
		// On an archive page the cover is the meaningful title page (the
		// page's own heading is the external text), so pre-select it.
		var cover = new OO.ui.CheckboxInputWidget( {
			selected: !!mw.config.get( 'wbPrintArchive' )
		} );
		var coverField = new OO.ui.FieldLayout( cover, {
			label: mw.msg( 'embeddablecontent-print-cover' ),
			help: mw.msg( 'embeddablecontent-print-cover-help' ),
			align: 'inline'
		} );
		var printButton = new OO.ui.ButtonWidget( {
			label: mw.msg( 'embeddablecontent-print-go' ),
			flags: [ 'primary', 'progressive' ]
		} );
		printButton.on( 'click', function () {
			closeActive();
			doPrint( cover.isSelected() );
		} );

		var $content = $( '<div class="wb-print-popup"></div>' )
			.append( coverField.$element )
			.append( $( '<div class="wb-print-popup-actions"></div>' ).append( printButton.$element ) );

		showPopup( $anchor, $content, mw.msg( 'embeddablecontent-print-button' ) );
	}

	/** Open the Download menu: PDF / HTML / wikitext. */
	function openDownloadMenu( $anchor ) {
		var options = [
			{ key: 'pdf', label: mw.msg( 'embeddablecontent-download-pdf' ) },
			{ key: 'html', label: mw.msg( 'embeddablecontent-download-html' ) },
			{ key: 'wikitext', label: mw.msg( 'embeddablecontent-download-wikitext' ) }
		];
		var $content = $( '<div class="wb-download-menu"></div>' );
		options.forEach( function ( option ) {
			var button = new OO.ui.ButtonWidget( {
				label: option.label,
				framed: false,
				classes: [ 'wb-download-option' ]
			} );
			button.$element.attr( 'data-download', option.key );
			button.on( 'click', function () {
				closeActive();
				if ( option.key === 'pdf' ) {
					openPrintPopup( $anchor );
				} else if ( option.key === 'html' ) {
					downloadHtml();
				} else {
					downloadWikitext();
				}
			} );
			$content.append( button.$element );
		} );
		showPopup( $anchor, $content, mw.msg( 'embeddablecontent-download-menu-title' ) );
	}

	/**
	 * The page-toolbar row the button joins. `wbPrintToolbar` (set by
	 * Hooks::wirePrintModule) picks the surface: 'content' → the inline
	 * `.wb-content-page-toolbar` on ordinary content pages, 'embed' → the
	 * shared `.wb-embed-toolbar` row below the title (classic per-kind pages,
	 * and every other article page). The row is created when absent — the
	 * sibling toolbar modules reuse it (the same create-or-reuse contract
	 * they use themselves), so the print button lands in ONE row with the
	 * other page actions regardless of module load order (print loads first).
	 * The block row is the shared toolbar primitive's getRow(), which inserts
	 * it below the page-title chrome — never after `#firstHeading` (a flex
	 * item in Vector 2022's titlebar).
	 */
	function getToolbar() {
		if ( mw.config.get( 'wbPrintToolbar' ) !== 'content' ) {
			return mw.embeddableContent.toolbar.getRow();
		}
		var $toolbar = $( '.wb-content-page-toolbar' ).first();
		if ( $toolbar.length === 0 ) {
			$toolbar = $( '<span class="wb-content-page-toolbar"></span>' );
			$( '#firstHeading' ).append( $toolbar );
		}
		return $toolbar;
	}

	/** The "Download" menu button, rendered into the page-toolbar row. */
	function addToolbarButton() {
		if ( $( '#firstHeading' ).length === 0 || $( '#ca-wb-print' ).length > 0 ) {
			return;
		}
		mw.embeddableContent.toolbar.add(
			getToolbar(),
			$( '<button>' )
				.attr( 'id', 'ca-wb-print' )
				.attr( 'type', 'button' )
				.attr( 'aria-haspopup', 'true' )
				.addClass( 'wb-embed-toolbar-btn wb-download-btn' )
				.attr( 'title', mw.msg( 'embeddablecontent-download-button-hint' ) )
				.text( mw.msg( 'embeddablecontent-download-button' ) )
				.on( 'click', function () { openDownloadMenu( $( this ) ); } ),
			mw.embeddableContent.toolbar.ORDER.print
		);
	}

	/**
	 * Intercept the core sidebar "Printable version" link, on any skin. The
	 * link is a `javascript:print();` anchor AND mediawiki.page.ready binds
	 * its OWN handler on it that calls `window.print()` — so a delegated
	 * (bubble-phase) listener runs too late and the print dialog opens
	 * alongside the popup. Capturing in the CAPTURE phase runs before the
	 * element's handler; stopping propagation there keeps core's
	 * `window.print()` from firing, so only the cover-page popup opens.
	 */
	function onPrintLinkClick( e ) {
		var target = e.target;
		if ( !target || typeof target.closest !== 'function' ) {
			return;
		}
		var link = target.closest( 'a[href="javascript:print();"], #t-print a' );
		if ( !link ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		openPrintPopup( $( link ) );
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.print = { open: openDownloadMenu, print: openPrintPopup };

	mw.loader.using( [ 'mediawiki.api', 'mediawiki.notification', 'oojs-ui' ] ).then( function () {
		window.addEventListener( 'afterprint', cleanup );
		// Belt-and-braces: some browsers fire beforeprint after our
		// setPrintDocumentTitle() but before the dialog reads the title.
		window.addEventListener( 'beforeprint', function () {
			if ( $( document.body ).hasClass( 'wb-printing' ) ) {
				setPrintDocumentTitle();
			}
		} );

		addToolbarButton();

		// Capture phase: beats mediawiki.page.ready's own #t-print a handler
		// (which calls window.print() before a bubble-phase listener could).
		document.addEventListener( 'click', onPrintLinkClick, true );
	} );
}() );
