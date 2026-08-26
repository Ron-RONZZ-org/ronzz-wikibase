/**
 * Confirmation banners for entity fields auto-filled from fetched source
 * data (the autofill-confirm flow): the server renders
 * `<div class="wb-entity-confirm" data-field="wppublisher">…` inside a
 * field's help slot with the copy
 *   "{field} fetched from source: {value}, we think this corresponds to
 *    {label} ({id})." [Yes, that's right] [No, let me correct]
 *
 * The field is ALREADY prefilled with the matched item id by the server
 * (review/manual forms) or by uploadmeta.js (Special:Upload / Add*
 * portrait/logo validate). This module wires the buttons:
 *   - "Yes, that's right"  → keep the prefilled value, dismiss the banner;
 *   - "No, let me correct" → clear the field and focus the combobox so the
 *     user picks another item (the banner is dismissed).
 *
 * Field resolution: the rendered HTMLForm input name is "wp" + field key
 * (e.g. `wppublisher`), which the config passes in data-field. OOUI forms
 * give the <input> an auto-generated id, so the lookup goes by NAME — the
 * same convention uploadmeta.js uses.
 */
( function () {
	'use strict';

	/** The field's real <input>, by name (the data-field value). */
	function fieldInput( name ) {
		if ( !name ) {
			return null;
		}
		var byName = document.querySelector( 'input[name="' + name + '"]' );
		if ( byName ) {
			return byName;
		}
		// Fallback: the element id itself (php-mode forms with explicit ids).
		var byId = document.getElementById( name );
		if ( byId && byId.tagName === 'INPUT' ) {
			return byId;
		}
		return null;
	}

	mw.loader.using( 'oojs-ui' ).then( function () {
		$( '.wb-entity-confirm' ).each( function () {
			var $banner = $( this );
			var input = fieldInput( String( $banner.data( 'field' ) || '' ) );
			if ( !input ) {
				// The field is hidden or absent (e.g. a hide-if toggled
				// section) — nothing to confirm.
				$banner.hide();
				return;
			}
			$banner.find( '.wb-entity-confirm-yes' ).on( 'click', function () {
				$banner.remove();
			} );
			$banner.find( '.wb-entity-confirm-no' ).on( 'click', function () {
				$( input ).val( '' ).trigger( 'change' ).trigger( 'focus' );
				$banner.remove();
			} );
		} );
	} );
}() );
