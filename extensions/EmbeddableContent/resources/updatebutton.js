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

	function getToolbar() {
		var $toolbar = $( '.wb-embed-toolbar' );
		if ( $toolbar.length === 0 ) {
			$toolbar = $( '<div class="wb-embed-toolbar"></div>' );
			$( '#firstHeading' ).after( $toolbar );
		}
		return $toolbar;
	}

	mw.loader.using( 'oojs-ui' ).then( function () {
		var url = mw.config.get( 'wbUpdateBasicInfoUrl' );
		if ( !url || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		// The button text follows the item kind (the server sets the
		// message KEY in wbUpdateBasicInfoLabel): "Update basic
		// information" for the semantic entities, "Edit content" for the
		// quotation/math/code-snippet content items.
		var labelKey = mw.config.get( 'wbUpdateBasicInfoLabel' ) || 'embeddablecontent-update-button';
		// prepend: whichever module renders first (this one or the gadget),
		// the update button is the PRIMARY action and stays first in the row.
		getToolbar().prepend(
			$( '<a class="wb-embed-toolbar-btn wb-update-basic-btn"></a>' )
				.attr( 'href', url )
				.text( mw.msg( labelKey ) )
		);
	} );
}() );
