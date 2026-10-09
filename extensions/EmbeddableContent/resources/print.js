/**
 * "Printable version" enhancements (the print batch). Loaded on every
 * existing article page (Hooks::onBeforePageDisplay sets wbPrintTitle =
 * Title::getSubpageText() — the namespace and the subpage parent are
 * dropped, e.g. "User:Rongzhou/Construction site visit report" prints as
 * "Construction site visit report", "Source:L111-1 of Code de
 * l'éducation (Legislation)" as "L111-1 of Code de l'éducation
 * (Legislation)").
 *
 * Two entry points, one flow:
 *   - the core sidebar "Printable version" link (`#t-print a`, whose
 *     `javascript:print();` is intercepted);
 *   - a "Print this page" button added to the page-title toolbar
 *     (`.wb-print-toolbar`, the content-page toolbar pattern).
 *
 * Both open the shared print popup (the citationpopup.js OOUI
 * PopupWidget pattern): an "Add a cover page" checkbox + a Print button.
 * On print the module fetches the page's registered, non-bot contributors
 * (action=query&prop=contributors&pcexcludegroup=bot), renders the title
 * and a centered "by A, B, and C" line (and the optional centered cover
 * page, page-break-after), then calls window.print() and cleans up on
 * afterprint.
 *
 * Ctrl-P (without either entry point) keeps the browser default — a print
 * output cannot be retro-fitted after the fact.
 *
 * Exposes: mw.embeddableContent.print.open( $anchor ).
 */
( function () {
	'use strict';

	var activePopup = null;

	function closeActive() {
		if ( activePopup ) {
			activePopup.$element.remove();
			activePopup = null;
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

	/** Fetch the authors, inject the print DOM, then open the print dialog. */
	function doPrint( withCover ) {
		fetchAuthors().then( function ( names ) {
			var title = mw.config.get( 'wbPrintTitle' ) || '';
			var authorLine = names.length > 0
				? mw.msg( 'embeddablecontent-print-by', listAuthors( names ) )
				: '';
			inject( title, authorLine, withCover );
			window.print();
		} );
	}

	/** Open the print popup (cover-page option + Print) anchored to $anchor. */
	function open( $anchor ) {
		closeActive();

		var cover = new OO.ui.CheckboxInputWidget( { selected: false } );
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

		var popup = new OO.ui.PopupWidget( {
			$content: $content,
			$floatableContainer: $anchor,
			padded: true,
			autoFlip: true,
			head: true,
			label: mw.msg( 'embeddablecontent-print-button' )
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

	/** The inline "Print this page" button (the page-title toolbar pattern). */
	function addToolbarButton() {
		if ( $( '#firstHeading' ).length === 0 || $( '#ca-wb-print' ).length > 0 ) {
			return;
		}
		var $toolbar = $( '<span class="wb-print-toolbar"></span>' ).append(
			$( '<button>' )
				.attr( 'id', 'ca-wb-print' )
				.attr( 'type', 'button' )
				.addClass( 'wb-embed-toolbar-btn' )
				.attr( 'title', mw.msg( 'embeddablecontent-print-button-hint' ) )
				.text( mw.msg( 'embeddablecontent-print-button' ) )
				.on( 'click', function () { open( $( this ) ); } )
		);
		$( '#firstHeading' ).append( $toolbar );
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.print = { open: open };

	mw.loader.using( [ 'mediawiki.api', 'oojs-ui' ] ).then( function () {
		window.addEventListener( 'afterprint', cleanup );

		addToolbarButton();

		// Intercept the core sidebar "Printable version" link on any skin
		// (delegated, so it works regardless of when the portlet renders).
		$( document ).on( 'click', 'a[href="javascript:print();"]', function ( e ) {
			e.preventDefault();
			open( $( this ) );
		} );
	} );
}() );
