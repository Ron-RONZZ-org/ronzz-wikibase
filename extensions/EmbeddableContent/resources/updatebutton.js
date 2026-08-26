/**
 * "Update basic information" button on entity pages whose item class has a
 * Special:Update* counterpart (person / source / collective / software /
 * fictional character). The server sets the target URL in
 * mw.config wbUpdateBasicInfoUrl when the item's instance-of matches the
 * Add* vocabulary (Hooks::onBeforePageDisplay); this module renders the
 * button under the page title, next to the embed/citation toolbar.
 */
( function () {
	'use strict';

	mw.loader.using( 'oojs-ui' ).then( function () {
		var url = mw.config.get( 'wbUpdateBasicInfoUrl' );
		if ( !url || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		$( '#firstHeading' ).after(
			$( '<div class="wb-update-basic-toolbar"></div>' ).append(
				$( '<a class="wb-embed-toolbar-btn wb-update-basic-btn"></a>' )
					.attr( 'href', url )
					.text( mw.msg( 'embeddablecontent-update-button' ) )
			)
		);
	} );
}() );
