/*
 * Rendered preview of a content item (quotation / math / code snippet).
 * Content items carry NO classic page — the Item page is the only place to
 * see them — so the server sets wbContentPreviewItem (the Q-id, only for
 * items classified under a content class) and this module fetches the
 * in-wiki preview variant (action=embed&preview=1) and injects it directly
 * BELOW the entity toolbar (.wb-embed-toolbar).
 *
 * For a quotation the preview shows the ORIGINAL text, the reader-language
 * translation when one exists, and the attribution line; for a math item it
 * shows the expression and its note (inline $…$ typeset with the vendored
 * KaTeX, since SimpleMathJax is not loaded on the Item page).
 *
 * Exposes mw.embeddableContent.preview.render( $container, itemId ) so the
 * Add* success popup (resources/addmore.js) reuses the exact same preview.
 */
( function () {
	'use strict';

	var ID_PATTERN = /^Q[1-9]\d*$/;

	/**
	 * Injects the fetched fragment into $container and asks the embed
	 * renderers to (re-)render: inline $…$ via the shared KaTeX helper, then
	 * math.js / code.js via the embedded-content hook.
	 */
	function mount( $container, html ) {
		$container.html( html );
		mw.embeddableContent.typesetInlineMath( $container[ 0 ] );
		mw.hook( 'ext.embeddableContent.embedContentAdded' ).fire( $container );
	}

	/**
	 * Fetches and renders the preview of an item into $container. Resolves
	 * true when the item is embeddable, false otherwise (never fatal).
	 *
	 * @param {jQuery} $container
	 * @param {string} itemId
	 * @return {jQuery.Promise<boolean>}
	 */
	function render( $container, itemId ) {
		var api = new mw.Api();
		return api.get( {
			action: 'embed',
			entity: itemId,
			output: 'html',
			preview: 1,
			lang: mw.config.get( 'wgUserLanguage' ) || 'en'
		} ).then( function ( data ) {
			var html = data && data.embed && data.embed.html;
			if ( !data || data.error || typeof html !== 'string' || html === '' ) {
				return false;
			}
			mount( $container, html );
			return true;
		} );
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.preview = { render: render };

	// The Item-page auto-preview (the server sets wbContentPreviewItem).
	var item = mw.config.get( 'wbContentPreviewItem' );
	if ( typeof item === 'string' && !ID_PATTERN.test( item ) ) {
		item = null;
	}
	if ( !item || $( '#firstHeading' ).length === 0 ) {
		return;
	}
	mw.loader.using( [ 'mediawiki.api', 'ext.embeddableContent.embed' ] ).then( function () {
		var $preview = $( '<div class="wb-content-preview"></div>' );
		var $toolbar = $( '.wb-embed-toolbar' );
		if ( $toolbar.length > 0 ) {
			$toolbar.after( $preview );
		} else {
			$( '#firstHeading' ).after( $preview );
		}
		render( $preview, item );
	} );
}() );
