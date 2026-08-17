/**
 * Entity search + autofill for the provenance comboboxes
 * (issue #7: Special:AddQuotation / AddCodeSnippet / AddMath provenance block).
 *
 * The server renders the fields as OOUI ComboBoxInputWidgets
 * (HTMLForm type `combobox`, cssclass `wb-entity-combobox`); this module
 * wires them to wbsearchentities (the instance's own Wikibase API): typing
 * suggests matching entities, picking one fills the field with its item id.
 * The submitted value remains an item id (e.g. "Q42").
 */
( function () {
	'use strict';

	mw.loader.using( 'oojs-ui' ).then( function () {
		// HTMLForm puts the cssclass on BOTH the outer field wrapper
		// (.mw-htmlform-field-HTMLComboboxField) and the OOUI widget; only
		// the widget carries data-ooui and can be infused. Selecting both and
		// infusing the outer one throws, aborting the .each() loop and
		// leaving every combobox unwired.
		$( '.wb-entity-combobox[data-ooui]' ).each( function () {
			var $el = $( this );
			var combo = OO.ui.ComboBoxInputWidget.static.infuse( $el );
			var api = new mw.Api();
			var pending = null;

			combo.on( 'change', OO.ui.debounce( function ( value ) {
				value = String( value || '' ).trim();
				if ( value.length < 2 || /^[QP]\d+$/i.test( value ) ) {
					// Emptied input or a typed entity id: clear stale
					// suggestions so a later retype starts fresh.
					if ( value === '' || /^[QP]\d+$/i.test( value ) ) {
						combo.setOptions( [] );
						combo.getMenu().toggle( false );
					}
					return;
				}
				// Keep the RAW api.get() request as `pending`: a .then()
				// derivative is a plain promise without .abort(), so the
				// second search used to throw "pending.abort is not a
				// function" and never update the suggestions.
				if ( pending ) {
					pending.abort();
				}
				pending = api.get( {
					action: 'wbsearchentities',
					search: value,
					language: mw.config.get( 'wgUserLanguage' ) || 'en',
					type: 'item',
					limit: 10,
					format: 'json'
				} );
				pending.then( function ( data ) {
					var options = ( data.search || [] ).map( function ( row ) {
						var label = row.id;
						if ( row.label ) {
							label = row.id + ' — ' + row.label;
						}
						if ( row.description ) {
							label += ' (' + row.description + ')';
						}
						return { data: row.id, label: label };
					} );
					combo.setOptions( options );
					// setOptions does not open the menu on its own.
					combo.getMenu().toggle( true );
				} ).catch( function () {
					// Non-fatal: the combobox still accepts a typed item id,
					// which the server-side parseOptionalItemId validates.
				} );
			}, 250 ) );
		} );
	} );
}() );
