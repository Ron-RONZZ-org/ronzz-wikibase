/*
 * The AddSource/UpdateSource `additionalLanguages` field: a comma-separated
 * multi-value combobox over the ISO 639 catalog.
 *
 * The server renders the field as an OOUI ComboBoxInputWidget (HTMLForm type
 * `combobox`, cssclass `wb-language-combobox-multi`). A static-options OOUI
 * combobox REPLACES its value with the picked option's data on choose, so
 * this module re-assembles the list: it remembers the value the combobox
 * held when the menu opened (the committed codes) and appends the pick,
 * dropping duplicates. The user can also type a comma/semicolon-separated
 * list directly; the server parses and validates it.
 */
( function () {
	'use strict';

	function splitList( value ) {
		return String( value || '' ).split( /[,;]\s*/ ).map( function ( segment ) {
			return String( segment || '' ).trim();
		} ).filter( function ( segment ) {
			return segment !== '';
		} );
	}

	mw.loader.using( 'oojs-ui' ).then( function () {
		// Target the widget element itself (its OOUI class), not the
		// FieldLayout wrapper — the entitysuggest.js pattern.
		$( '.wb-language-combobox-multi.oo-ui-comboBoxInputWidget' ).each( function () {
			var combo = OO.ui.ComboBoxInputWidget.static.infuse( this );
			if ( !combo ) {
				return;
			}
			// The committed codes as of the menu opening — the value the
			// user has NOT yet edited into a search query.
			var committed = String( combo.$input.val() || '' );

			combo.getMenu().on( 'toggle', function ( visible ) {
				if ( visible ) {
					committed = String( combo.$input.val() || '' );
				}
			} );

			combo.getMenu().on( 'choose', function ( item ) {
				if ( !item || item.getData === undefined ) {
					return;
				}
				var data = String( item.getData() );
				var picked = splitList( committed );
				if ( picked.indexOf( data ) === -1 ) {
					picked.push( data );
				}
				var joined = picked.join( ', ' );
				combo.setValue( joined );
				committed = joined;
			} );
		} );
	} );
}() );
