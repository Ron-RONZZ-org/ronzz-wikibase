/**
 * "Update basic information" / "Edit content" button on entity pages whose
 * item class has a Special:Update* counterpart (person / source /
 * collective / software / fictional character / quotation / math /
 * code snippet). The server sets the target URL in mw.config
 * wbUpdateBasicInfoUrl and the button's message KEY in
 * wbUpdateBasicInfoLabel when the item's instance-of matches the Add*
 * vocabulary (Hooks::onBeforePageDisplay); this module renders the button
 * into the SHARED .wb-embed-toolbar row (created on first use, reused by
 * the gadget module's embed/citation buttons — one row under the page
 * title).
 */
( function () {
	'use strict';

	mw.loader.using( [ 'oojs-ui', 'ext.embeddableContent.toolbar' ] ).then( function () {
		var url = mw.config.get( 'wbUpdateBasicInfoUrl' );
		if ( !url || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		// The button text follows the item kind (the server sets the
		// message KEY in wbUpdateBasicInfoLabel): "Update basic
		// information" for the semantic entities, "Edit content" for the
		// quotation/math/code-snippet content items.
		var labelKey = mw.config.get( 'wbUpdateBasicInfoLabel' ) || 'embeddablecontent-update-button';
		var $button = $( '<a class="wb-embed-toolbar-btn wb-update-basic-btn"></a>' )
			.attr( 'href', url )
			.text( mw.msg( labelKey ) );
		// The update button is the PRIMARY action (rank 10). On a File: page
		// it joins the EXISTING file toolbar (copy embed / copy link) instead
		// of creating its own block row below the title; elsewhere it joins
		// the shared block row.
		function place( $row ) {
			mw.embeddableContent.toolbar.add(
				$row,
				$button,
				mw.embeddableContent.toolbar.ORDER.update
			);
		}
		if ( mw.config.get( 'wgNamespaceNumber' ) === 6 ) {
			var $fileToolbar = $( '.wb-file-toolbar' ).first();
			if ( $fileToolbar.length ) {
				place( $fileToolbar );
				return;
			}
			// resources/filepage.js may still be building the toolbar; join
			// it as soon as it fires the ready hook.
			mw.hook( 'embeddableContent.fileToolbarReady' ).add( function ( $toolbar ) {
				if ( $toolbar && !$button.parent().length ) {
					place( $toolbar );
				}
			} );
			return;
		}
		place( mw.embeddableContent.toolbar.getRow() );
	} );
}() );
