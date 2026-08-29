/* eslint-disable no-jquery/no-global-selector */
/*
 * Entity-page toolbar: "update basic information" (updatebutton.js),
 * "copy embed" + "copy citation" buttons, prominently displayed under the
 * page title in ONE row (issue #6 §4.4 — follow-up: visible buttons instead
 * of portlet links hidden in the ⋯ "More options" menu; the update button
 * and the embed/citation buttons share the same .wb-embed-toolbar flex row).
 *
 * Embed snippets use an ABSOLUTE URL (wgServer + path) — the iframe is meant
 * to be pasted on third-party sites. Multi-language quotations offer a
 * language selector: auto (server negotiates), all languages (?lang=all), or
 * a specific language.
 *
 * Copy citation offers a FORMAT selector: APA / Vancouver / BibTeX / RIS
 * (the four text formats api.php?action=citation supports; json is a raw
 * structure, not meant for copying). The text for the selected format is
 * fetched lazily and cached per format.
 *
 * The toolbar renders only the actions that apply to the item: the embed
 * button appears when the item is embeddable (action=embed succeeds), the
 * citation button when a citation can be built (action=citation succeeds).
 */
( function () {
	'use strict';

	var ID_PATTERN = /^Q[1-9]\d*$/;
	var entityId = null;
	var titleText = mw.config.get( 'wgTitle' ) || '';
	var embedLang = ''; // '' = auto, 'all' = all languages, else a language code

	// Citation text formats, in display order. The select and the button
	// share the currently selected format (citationStyle).
	var CITATION_STYLES = [
		{ key: 'apa', label: 'APA' },
		{ key: 'vancouver', label: 'Vancouver' },
		{ key: 'bibtex', label: 'BibTeX' },
		{ key: 'ris', label: 'RIS' }
	];
	var citationText = {}; // style key => formatted text (fetched lazily)
	var citationStyle = 'apa';

	if ( ID_PATTERN.test( titleText ) ) {
		entityId = titleText;
	}

	/**
	 * The shared toolbar row under the page title: created on first use by
	 * whichever module runs first (this one or updatebutton.js), reused by
	 * the other — the buttons always end up in the same flex row.
	 */
	function getToolbar() {
		var $toolbar = $( '.wb-embed-toolbar' );
		if ( $toolbar.length === 0 ) {
			$toolbar = $( '<div class="wb-embed-toolbar"></div>' );
			$( '#firstHeading' ).after( $toolbar );
		}
		return $toolbar;
	}

	/**
	 * Appends controls after the "Update basic information" button when it is
	 * present, so the primary action stays first in the row.
	 */
	function appendControls( controls ) {
		var $update = getToolbar().find( '.wb-update-basic-btn' );
		if ( $update.length > 0 ) {
			$update.after( controls );
		} else {
			getToolbar().append( controls );
		}
	}

	function embedSnippet() {
		var server = mw.config.get( 'wgServer' ) || '';
		var params = embedLang ? { lang: embedLang } : {};
		return '<iframe src="' + server + mw.util.getUrl( 'Special:Embed/' + entityId, params ) +
			'" loading="lazy" style="width:100%;border:0;min-height:120px"></iframe>';
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

	function makeButton( id, messageKey, handler ) {
		return $( '<button>' )
			.attr( 'id', id )
			.attr( 'type', 'button' )
			.addClass( 'wb-embed-toolbar-btn' )
			.text( mw.msg( messageKey ) )
			.on( 'click', handler );
	}

	/**
	 * Embed button + (for multi-language quotations) a language selector.
	 *
	 * @param {Object} languages code => text, from the embed API response
	 * @return {jQuery[]} toolbar children for the embed action
	 */
	function embedControls( languages ) {
		var $btn = makeButton( 'ca-wb-embed-copy', 'embeddablecontent-gadget-copyembed', function () {
			copyText( embedSnippet() );
		} );
		var controls = [ $btn ];
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
			controls.push( $select );
		}
		return controls;
	}

	/**
	 * Fetches and caches the citation text for a format. Best-effort: a
	 * failed fetch leaves the previous text cached (the button copies
	 * whatever is available for the selected format).
	 *
	 * @param {mw.Api} api
	 * @param {string} style
	 */
	function fetchCitationText( api, style ) {
		if ( citationText[ style ] || !entityId ) {
			return;
		}
		api.get( { action: 'citation', entity: entityId, style: style, output: 'text' } )
			.done( function ( data ) {
				if ( data && data.citation && !data.error ) {
					citationText[ style ] = data.citation;
				}
			} );
	}

	/**
	 * Copy-citation button + the format selector. The button copies the text
	 * of the currently selected format; changing the selector fetches (and
	 * caches) that format's text.
	 *
	 * @param {mw.Api} api
	 * @return {jQuery[]} toolbar children for the citation action
	 */
	function citationControls( api ) {
		var $btn = makeButton( 'ca-wb-embed-cite', 'embeddablecontent-gadget-copycitation', function () {
			copyText( citationText[ citationStyle ] || '' );
		} );
		var $select = $( '<select>' )
			.addClass( 'wb-embed-toolbar-style' )
			.attr( 'title', mw.msg( 'embeddablecontent-gadget-citation-style' ) );
		CITATION_STYLES.forEach( function ( style ) {
			$select.append( $( '<option>' ).val( style.key ).text( style.label ) );
		} );
		$select.val( citationStyle );
		$select.on( 'change', function () {
			citationStyle = $select.val();
			fetchCitationText( api, citationStyle );
		} );
		return [ $btn, $select ];
	}

	mw.loader.using( [ 'mediawiki.api', 'mediawiki.notification' ] ).then( function () {
		if ( !entityId || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		var api = new mw.Api();
		var children = [];
		var checkDone = 0;

		function maybeRender() {
			checkDone++;
			if ( checkDone < 2 ) {
				return;
			}
			if ( children.length > 0 ) {
				appendControls( children );
			}
		}

		// Embed button: only for embeddable items (the API reports the
		// available payload languages for the selector — only in json mode).
		api.get( { action: 'embed', entity: entityId, output: 'json' } ).done( function ( data ) {
			if ( !data.error && data.embed ) {
				children = children.concat( embedControls( data.embed.languages ) );
			}
			maybeRender();
		} ).fail( maybeRender );

		// Citation button + format selector: only when a citation can be
		// built. The APA probe doubles as the first fetched text.
		api.get( { action: 'citation', entity: entityId, style: 'apa', output: 'text' } ).done( function ( data ) {
			var citation = ( data && data.citation && !data.error ) ? data.citation : '';
			if ( citation ) {
				citationText.apa = citation;
				children = children.concat( citationControls( api ) );
			}
			maybeRender();
		} ).fail( maybeRender );
	} );
}() );
