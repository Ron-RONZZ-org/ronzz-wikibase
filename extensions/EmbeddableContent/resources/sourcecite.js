/**
 * "Copy internal citation" button on Source: classic pages — copies the
 * wikitext snippet `<ref>{{#cite:Q42}}</ref>` so an editor can cite the
 * page's source item on any wiki page (the {{#cite:}} parser function
 * inside a stock-Cite <ref>).
 *
 * The server resolves the page → item id (site-link store) and sets
 * wbInternalCiteItem (Hooks::onBeforePageDisplay, NS_SOURCE branch); this
 * module renders ONE button into the shared `.wb-embed-toolbar` row under
 * the page title — the button itself comes from the shared entity-actions
 * primitive (resources/entityactions.js), so the Source: page and the Add*
 * success popup can never drift.
 */
( function () {
	'use strict';

	mw.loader.using( 'ext.embeddableContent.entityactions' ).then( function () {
		var qid = mw.config.get( 'wbInternalCiteItem' );
		if ( !qid || !/^Q[1-9]\d*$/.test( qid ) || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		var $row = mw.embeddableContent.toolbar.getRow();
		$row.append(
			mw.embeddableContent.entityActions.internalCitationControls( qid )
		);
		mw.embeddableContent.toolbar.sort( $row );
	} );
}() );
