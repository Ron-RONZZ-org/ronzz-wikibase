/* eslint-disable no-jquery/no-global-selector */
/*
 * Item toolbar: "update basic information" (updatebutton.js), "copy embed" +
 * "copy citation" buttons, prominently displayed under the page title in ONE
 * row (issue #6 §4.4 — follow-up: visible buttons instead of portlet links
 * hidden in the ⋯ "More options" menu; the update button and the
 * embed/citation buttons share the same .wb-embed-toolbar flex row).
 *
 * Rendered on the Item: pages AND on the classic per-kind pages
 * (Source:/FOSS:/Person:/Collective:/Software:) sitelinked to an item —
 * feature parity. The item id comes from the wbEmbedItem config var (set by
 * Hooks::wireItemToolbar); wgTitle is only a fallback for an entity page.
 *
 * The controls themselves live in the shared ext.embeddableContent.entityactions
 * module (also used by the Add* success popup): "Copy embed code" offers the
 * internal {{#content:Q42}} vs the external <iframe> flavour (+ a language
 * selector for multi-language quotations); "Copy citation" opens the shared
 * citation popup (APA default / Vancouver / BibTeX / RIS). The toolbar
 * renders only the actions that apply to the item (API probes).
 */
( function () {
	'use strict';

	var ID_PATTERN = /^Q[1-9]\d*$/;
	var entityId = null;
	var configItem = mw.config.get( 'wbEmbedItem' );
	var titleText = mw.config.get( 'wgTitle' ) || '';

	// The server sets wbEmbedItem for BOTH entity pages and the classic
	// per-kind pages (Source:/FOSS:/Person:/Collective:/Software:), where
	// wgTitle is the page title, not the Q-id — prefer it. The wgTitle
	// fallback covers an entity page rendered without the config var.
	if ( typeof configItem === 'string' && ID_PATTERN.test( configItem ) ) {
		entityId = configItem;
	} else if ( ID_PATTERN.test( titleText ) ) {
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

	mw.loader.using( [ 'mediawiki.api', 'mediawiki.notification' ] ).then( function () {
		if ( !entityId || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		// The controls append to the shared row; updatebutton.js prepends the
		// primary "Update basic information" / "Edit content" button, so it
		// stays first.
		mw.embeddableContent.entityActions.attach( getToolbar(), entityId );
	} );
}() );
