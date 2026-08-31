/**
 * Entity search + autofill for the entity comboboxes
 * (issue #7: provenance blocks on AddQuotation / AddCodeSnippet / AddMath;
 * issue #26: Special:AddSoftware item-typed facts).
 *
 * The server renders the fields as OOUI ComboBoxInputWidgets
 * (HTMLForm type `combobox`, cssclass `wb-entity-combobox`); this module
 * wires them to action=entitysearch (the extension's FULLTEXT search — the
 * instance's wbsearchentities only matches exact/prefix, so "AGPL" could
 * never find "GNU AGPL-3.0" and "Einstein" never "Albert Einstein"):
 * typing suggests matching entities, picking one fills the field with its
 * item id. The submitted value remains an item id (e.g. "Q42").
 *
 * Fields with the extra cssclass `wb-entity-combobox-multi` accept SEVERAL
 * item ids, comma-separated ("Q42, Q179"). For those fields:
 *   - typing searches only the LAST comma/semicolon-separated segment, so a
 *     query runs against the freshly-typed text, not the whole list;
 *   - picking a suggestion REPLACES that trailing query segment with the
 *     picked id (previously-picked ids are kept, duplicates dropped).
 */
( function () {
	'use strict';

	mw.loader.using( 'oojs-ui' ).then( function () {
		// MW 1.46's OOUI HTMLForm renders TWO elements per combobox field
		// carrying the `wb-entity-combobox` cssclass AND a data-ooui:
		//   - the outer mw.htmlform.FieldLayout wrapper (data-ooui type
		//     "mw.htmlform.FieldLayout"), and
		//   - the actual OO.ui.ComboBoxInputWidget (data-ooui type
		//     "OO.ui.ComboBoxInputWidget").
		// Selecting `.wb-entity-combobox[data-ooui]` matches the wrapper
		// FIRST; infusing a FieldLayout as a ComboBoxInputWidget throws, and
		// the exception aborts the .each() loop — leaving EVERY combobox on
		// the page unwired (the Author(s) field symptom, all Add* pages).
		// Target the widget element itself via its OOUI class instead.
		$( '.wb-entity-combobox.oo-ui-comboBoxInputWidget' ).each( function () {
			var $el = $( this );
			var multi = $el.hasClass( 'wb-entity-combobox-multi' );
			var combo = OO.ui.ComboBoxInputWidget.static.infuse( $el );
			var api = new mw.Api();
			var pending = null;

			// The search query: for multi-value fields only the last
			// comma/semicolon-separated segment (what the user is typing).
			var querySegment = function ( value ) {
				var q = String( value || '' );
				if ( multi ) {
					q = q.split( /[,;]\s*/ ).pop();
				}
				return String( q || '' ).trim();
			};

			combo.on( 'change', OO.ui.debounce( function ( value ) {
				var q = querySegment( value );
				if ( q.length < 2 || /^[QP]\d+$/i.test( q ) ) {
					// Emptied input or a typed entity id: clear stale
					// suggestions so a later retype starts fresh.
					if ( q === '' || /^[QP]\d+$/i.test( q ) ) {
						combo.setOptions( [] );
						combo.getMenu().toggle( false );
					}
					return;
				}
				// Keep the RAW api.get() requests as `pending`: a .then()
				// derivative is a plain promise without .abort(), so the
				// second search used to throw "pending.abort is not a
				// function" and never update the suggestions.
				if ( pending ) {
					Array.isArray( pending ) ? pending.forEach( function ( r ) { r.abort(); } ) : pending.abort();
				}
				// One FULLTEXT query: the server module runs the raw +
				// title-cased + uppercase variants itself (the instance's
				// term store is case-sensitive, upstream T242644) and merges
				// the deduped hits.
				pending = api.get( {
					action: 'entitysearch',
					search: q,
					language: mw.config.get( 'wgUserLanguage' ) || 'en',
					limit: 10,
					format: 'json'
				} );
				Promise.resolve( pending ).then( function ( data ) {
					var seen = {};
					var options = [];
					( data.search || [] ).forEach( function ( row ) {
						if ( seen[ row.id ] ) {
							return;
						}
						seen[ row.id ] = true;
						var label = row.id;
						if ( row.label ) {
							label = row.id + ' — ' + row.label;
						}
						if ( row.description ) {
							label += ' (' + row.description + ')';
						}
						options.push( { data: row.id, label: label } );
					} );
					combo.setOptions( options );
					// setOptions does not open the menu on its own.
					combo.getMenu().toggle( options.length > 0 );
				} ).catch( function () {
					// Non-fatal: the combobox still accepts a typed item id,
					// which the server-side parse validates.
				} );
			}, 250 ) );

			if ( multi ) {
				// The widget's own menu-choose handler REPLACES the whole
				// value with the picked id — which would drop every
				// previously-picked id. To rebuild the list we need the value
				// as it was BEFORE the pick. A native DOM 'input' listener
				// captures exactly that: it fires on user typing/paste, but
				// NOT on programmatic setValue(), so it never sees the
				// widget's own post-pick overwrite.
				// NOTE: `combo.$input` IS the text-input element — this
				// OOUI's ComboBoxInputWidget has no `.input` sub-widget
				// (the old `combo.input.$input` crashed here, unwiring every
				// combobox on the page).
				var inputEl = combo.$input[ 0 ];
				var lastUserValue = String( inputEl ? inputEl.value : '' );
				if ( inputEl ) {
					inputEl.addEventListener( 'input', function () {
						lastUserValue = inputEl.value;
					} );
				}
				// 'choose' fires on suggestion activation (click/enter) —
				// AFTER the widget's own fill. Rebuild the full list from
				// lastUserValue, replacing its trailing query segment with
				// the picked id (duplicates dropped).
				combo.getMenu().on( 'choose', function ( item ) {
					if ( !item ) {
						return;
					}
					var id = String( item.getData() );
					var picked = [];
					( String( lastUserValue || '' ).split( /[,;]\s*/ ) ).forEach( function ( seg ) {
						seg = String( seg || '' ).trim().toUpperCase();
						if ( /^[QP]\d+$/.test( seg ) && picked.indexOf( seg ) === -1 ) {
							picked.push( seg );
						}
					} );
					if ( picked.indexOf( id ) === -1 ) {
						picked.push( id );
					}
					combo.setValue( picked.join( ', ' ) );
					combo.setOptions( [] );
					combo.getMenu().toggle( false );
				} );
			}
		} );
	} );
}() );
