/**
 * Shared entity action controls for the `.wb-embed-toolbar` row:
 *
 *   - "Copy embed code" — a flavour chooser (internal `{{#content:Q42}}` vs
 *     the external `Special:Embed` <iframe>) + a language selector for
 *     multi-language quotations;
 *   - "Copy citation" — opens the shared citation popup
 *     (resources/citationpopup.js);
 *   - "Copy internal citation" — copies `<ref>{{#cite:Q42}}</ref>` (the
 *     source-class action; opt-in per surface);
 *   - "Copy claim" — lists the item's statement properties and copies the
 *     page-quoting snippet (`{{#statements:<label>}}`).
 *
 * Used by the entity / classic-page toolbar (resources/gadget.js) and the
 * Add* success popup (resources/addmore.js), so both surfaces offer the same
 * actions with no drift. Each control carries its canonical rank
 * (`data-wb-order`), so the shared toolbar primitive
 * (ext.embeddableContent.toolbar) keeps one left→right order across
 * surfaces. Exposes:
 *
 *   mw.embeddableContent.entityActions.embedControls( entityId, languages )
 *   mw.embeddableContent.entityActions.citationControls( entityId )
 *   mw.embeddableContent.entityActions.internalCitationControls( entityId )
 *   mw.embeddableContent.entityActions.attach( $container, entityId, options )
 *
 * The embed/citation controls appear only when the item supports them (the
 * API probes inside attach()); `options.internalCitation` renders the
 * internal-citation button immediately (no probe — the class check is
 * server-side).
 */
( function () {
	'use strict';

	var toolbar = mw.embeddableContent.toolbar;
	var ORDER = toolbar.ORDER;

	/** Copy `text`, notifying on success. */
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

	function makeButton( id, messageKey, handler ) {
		return $( '<button>' )
			.attr( 'id', id )
			.attr( 'type', 'button' )
			.addClass( 'wb-embed-toolbar-btn' )
			.text( mw.msg( messageKey ) )
			.on( 'click', handler );
	}

	function embedSnippet( entityId, embedLang ) {
		var server = mw.config.get( 'wgServer' ) || '';
		var params = embedLang ? { lang: embedLang } : {};
		return '<iframe src="' + server + mw.util.getUrl( 'Special:Embed/' + entityId, params ) +
			'" loading="lazy" style="width:100%;border:0;min-height:120px"></iframe>';
	}

	/**
	 * The on-wiki (internal) embed snippet: the {{#content:Q42}} parser
	 * function renders the item's payload inline on any wiki page, with the
	 * language negotiated from the embedding page — no lang param needed.
	 */
	function contentSnippet( entityId ) {
		return '{{#content:' + entityId + '}}';
	}

	/**
	 * "Copy embed code" button + the flavour chooser (internal vs external)
	 * and, for multi-language quotations, a language selector. The language
	 * selector applies to the external iframe flavour ({{#content:}}
	 * negotiates from the embedding page).
	 *
	 * @param {string} entityId
	 * @param {Object} languages code => text, from the embed API response
	 * @return {jQuery[]} toolbar children
	 */
	function embedControls( entityId, languages ) {
		var embedLang = ''; // '' = auto, 'all' = all languages, else a language code
		var $chooser;
		var close = function () {
			if ( $chooser ) {
				$chooser.hide();
			}
		};
		var $btn = makeButton( 'ca-wb-embed-copy', 'embeddablecontent-gadget-copyembed', function () {
			if ( $chooser && $chooser.is( ':visible' ) ) {
				close();
				return;
			}
			if ( $chooser ) {
				$chooser.show();
			}
		} );
		$btn.attr( 'data-wb-order', ORDER.embed );

		$chooser = $( '<span class="wb-embed-embed-options" style="display:none"></span>' )
			.append( $( '<button>' )
				.attr( 'type', 'button' )
				.addClass( 'wb-embed-toolbar-btn' )
				.attr( 'id', 'ca-wb-embed-copy-internal' )
				.attr( 'title', mw.msg( 'embeddablecontent-gadget-embed-option-internal-hint' ) )
				.text( mw.msg( 'embeddablecontent-gadget-embed-option-internal' ) )
				.on( 'click', function () {
					copyText( contentSnippet( entityId ) );
					close();
				} ) )
			.append( $( '<button>' )
				.attr( 'type', 'button' )
				.addClass( 'wb-embed-toolbar-btn' )
				.attr( 'id', 'ca-wb-embed-copy-external' )
				.attr( 'title', mw.msg( 'embeddablecontent-gadget-embed-option-external-hint' ) )
				.text( mw.msg( 'embeddablecontent-gadget-embed-option-external' ) )
				.on( 'click', function () {
					copyText( embedSnippet( entityId, embedLang ) );
					close();
				} ) );
		$chooser.attr( 'data-wb-order', ORDER.embedChooser );

		var controls = [ $btn, $chooser ];
		if ( languages && Object.keys( languages ).length > 1 ) {
			var $select = $( '<select>' )
				.addClass( 'wb-embed-toolbar-lang' )
				.append( $( '<option>' ).val( '' ).text( mw.msg( 'embeddablecontent-gadget-embed-auto' ) ) )
				.append( $( '<option>' ).val( 'all' ).text( mw.msg( 'embeddablecontent-gadget-embed-all' ) ) );
			Object.keys( languages ).forEach( function ( code ) {
				$select.append( $( '<option>' ).val( code ).text( code ) );
			} );
			$select.on( 'change', function () {
				embedLang = $select.val();
			} );
			$select.attr( 'data-wb-order', ORDER.embedLang );
			controls.push( $select );
		}
		// Close the flavour chooser when clicking anywhere else.
		$( document ).on( 'click', function ( e ) {
			if ( !$chooser.is( ':visible' ) ) {
				return;
			}
			var $t = $( e.target );
			if ( $t.closest( '.wb-embed-embed-options' ).length || $t.closest( '#ca-wb-embed-copy' ).length ) {
				return;
			}
			close();
		} );
		return controls;
	}

	/**
	 * "Copy citation" button — opens the shared citation popup for the item.
	 *
	 * @param {string} entityId
	 * @return {jQuery[]} toolbar children
	 */
	function citationControls( entityId ) {
		var $btn = makeButton( 'ca-wb-embed-cite', 'embeddablecontent-gadget-copycitation', function () {
			mw.embeddableContent.citationPopup.open( $btn, { entity: entityId } );
		} );
		$btn.attr( 'data-wb-order', ORDER.citation );
		return [ $btn ];
	}

	/**
	 * "Copy internal citation" button — copies `<ref>{{#cite:Q42}}</ref>`, the
	 * wikitext snippet that cites the item from any wiki page through the
	 * stock Cite extension. Source pages render this action inline
	 * (resources/sourcecite.js delegates here); the Add* success popup shows
	 * it too.
	 *
	 * @param {string} entityId
	 * @return {jQuery[]} toolbar children
	 */
	function internalCitationControls( entityId ) {
		var $btn = makeButton(
			'ca-wb-source-cite-internal',
			'embeddablecontent-sourcecite-button',
			function () {
				copyText( '<ref>{{#cite:' + entityId + '}}</ref>' );
			}
		);
		$btn.attr( 'title', mw.msg( 'embeddablecontent-sourcecite-hint', entityId ) );
		$btn.attr( 'data-wb-order', ORDER.citeInternal );
		return [ $btn ];
	}

	/** The item id the current page represents (the page's sitelinked item). */
	function currentPageItem() {
		var id = mw.config.get( 'wbEmbedItem' ) || mw.config.get( 'wgWikibaseItemId' ) || '';
		if ( !id && /^Q[1-9]\d*$/.test( mw.config.get( 'wgTitle' ) || '' ) ) {
			id = mw.config.get( 'wgTitle' );
		}
		return id;
	}

	/**
	 * "Copy claim" button — lists the item's statement properties in a popup
	 * and copies the page-quoting snippet for the chosen one:
	 *   {{#statements:<label>}}            (the item is the page's item)
	 *   {{#statements:<label>|from=Q42}}   (any other page)
	 * Read-only (wbgetentities); the P-id is the fallback label.
	 *
	 * @param {string} entityId
	 * @return {jQuery[]} toolbar children
	 */
	function claimControls( entityId ) {
		var $btn = makeButton( 'ca-wb-claim', 'embeddablecontent-gadget-copyclaim', function () {
			openClaimPopup( $btn, entityId );
		} );
		$btn.attr( 'title', mw.msg( 'embeddablecontent-gadget-copyclaim-hint', entityId ) );
		$btn.attr( 'data-wb-order', ORDER.claim );
		return [ $btn ];
	}

	function openClaimPopup( $anchor, entityId ) {
		var api = new mw.Api();
		var $body = $( '<div class="wb-claim-popup"></div>' )
			.text( mw.msg( 'embeddablecontent-claimpopup-loading' ) );
		var popup = new OO.ui.PopupWidget( {
			$content: $body,
			$floatableContainer: $anchor,
			padded: true,
			autoFlip: true,
			head: true,
			label: mw.msg( 'embeddablecontent-gadget-copyclaim' ),
			classes: [ 'wb-claim-popup-widget' ]
		} );
		popup.on( 'toggle', function ( visible ) {
			if ( !visible ) {
				popup.$element.remove();
			}
		} );
		$( document.body ).append( popup.$element );
		popup.toggle( true );

		api.get( {
			action: 'wbgetentities',
			ids: entityId,
			props: 'claims',
			format: 'json'
		} ).done( function ( data ) {
			var claims = ( data.entities && data.entities[ entityId ] &&
				data.entities[ entityId ].claims ) || {};
			var propIds = Object.keys( claims ).sort();
			if ( propIds.length === 0 ) {
				$body.text( mw.msg( 'embeddablecontent-claimpopup-empty' ) );
				return;
			}
			api.get( {
				action: 'wbgetentities',
				ids: propIds.slice( 0, 50 ).join( '|' ),
				props: 'labels',
				languages: 'en|fr|eo',
				languagefallback: 1,
				format: 'json'
			} ).done( function ( labels ) {
				var onPage = currentPageItem() === entityId;
				var $list = $( '<div class="wb-claim-popup-list"></div>' );
				propIds.forEach( function ( pid ) {
					var property = labels.entities && labels.entities[ pid ];
					var label = property && property.labels && property.labels.en &&
						property.labels.en.value;
					var spec = label || pid;
					var snippet = onPage
						? '{{#statements:' + spec + '}}'
						: '{{#statements:' + spec + '|from=' + entityId + '}}';
					$list.append( $( '<button>' )
						.attr( 'type', 'button' )
						.addClass( 'wb-claim-popup-item' )
						.attr( 'title', snippet )
						.text( label ? label + ' (' + pid + ')' : pid )
						.on( 'click', function () {
							copyText( snippet );
							popup.toggle( false );
						} ) );
				} );
				$body.empty().append( $list );
			} ).fail( function () {
				$body.text( mw.msg( 'embeddablecontent-claimpopup-error' ) );
			} );
		} ).fail( function () {
			$body.text( mw.msg( 'embeddablecontent-claimpopup-error' ) );
		} );
	}

	/**
	 * Probes the embed / citation APIs and appends the applicable controls to
	 * $container (best-effort: a failed probe simply adds nothing). When
	 * `options.internalCitation` is set, the internal-citation button is
	 * appended FIRST (it needs no probe — the server already resolved the
	 * source class).
	 *
	 * @param {jQuery} $container
	 * @param {string} entityId
	 * @param {Object} [options]
	 * @param {boolean} [options.internalCitation] render the internal citation
	 */
	function attach( $container, entityId, options ) {
		options = options || {};
		$container.append( claimControls( entityId ) );
		toolbar.sort( $container );
		if ( options.internalCitation ) {
			$container.append( internalCitationControls( entityId ) );
			toolbar.sort( $container );
		}
		var api = new mw.Api();
		api.get( { action: 'embed', entity: entityId, output: 'json' } ).done( function ( data ) {
			if ( !data.error && data.embed ) {
				$container.append( embedControls( entityId, data.embed.languages ) );
				toolbar.sort( $container );
			}
		} );
		api.get( { action: 'citation', entity: entityId, style: 'apa', output: 'text' } ).done( function ( data ) {
			if ( data && data.citation && !data.error ) {
				$container.append( citationControls( entityId ) );
				toolbar.sort( $container );
			}
		} );
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.entityActions = {
		embedControls: embedControls,
		citationControls: citationControls,
		claimControls: claimControls,
		internalCitationControls: internalCitationControls,
		attach: attach
	};
}() );
