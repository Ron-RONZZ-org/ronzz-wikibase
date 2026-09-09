/**
 * OpenStreetMap place search for the person place-of-birth/death comboboxes
 * (osm-places feature).
 *
 * The server renders the fields as OOUI ComboBoxInputWidgets (HTMLForm
 * type `combobox`, cssclass `wb-osm-combobox`); this module wires them to
 * the OpenStreetMap Nominatim search API (browser-first — Nominatim's own
 * integration guidance; the server never proxies search-as-you-type).
 * Typing suggests places (display name + feature type); picking one fills
 * the field with the canonical `node|way|relation/<id>` form that the
 * server-side validation (OsmPlace) and the property's formatter URL
 * (https://www.openstreetmap.org/$1) expect.
 *
 * A picked suggestion ALSO writes its display name into the field's hidden
 * label sibling (`wpplaceOfBirthOsmLabel` / `wpplaceOfDeathOsmLabel` — the
 * server's parallel `place of birth (label)`/`place of death (label)`
 * string statement, so Template:Person renders the human-readable label,
 * not the raw id). A value that is not a picked suggestion (a manually
 * typed id, an emptied input) clears the label — a stale label must never
 * ride along with a different id. A typed value that already IS an OSM id
 * (the Update flow's prefill) is left alone.
 *
 * Network failures are non-fatal — the server rejects an unpicked name on
 * submit with a clear error.
 */
( function () {
	'use strict';

	mw.loader.using( 'oojs-ui' ).then( function () {
		// Target the widget element itself (its OOUI class), not the
		// FieldLayout wrapper — same pattern as entitysuggest.js.
		$( '.wb-osm-combobox.oo-ui-comboBoxInputWidget' ).each( function () {
			var combo = OO.ui.ComboBoxInputWidget.static.infuse( this );
			var pending = null; // AbortController of the in-flight search
			// The label field is the hidden sibling named after the OSM id
			// field: placeOfBirthOsm → wpplaceOfBirthOsmLabel. Resolve it at
			// event time (never at wiring time) — the OOUI hide-if may
			// remove/re-insert the death fields when the deceased toggle
			// flips.
			var name = String( combo.$input.attr( 'name' ) || '' );
			var labelName = name ? name + 'Label' : '';
			var lastPicked = ''; // the id whose label is in the label field

			function labelInput() {
				if ( !labelName ) {
					return null;
				}
				return document.querySelector( 'input[name="' + labelName + '"]' );
			}

			function setLabel( value ) {
				var input = labelInput();
				if ( input ) {
					input.value = String( value || '' );
				}
			}

			combo.on( 'change', OO.ui.debounce( function ( value ) {
				var q = String( value || '' ).trim();
				var isId = /^(node|way|relation)\/\d+$/i.test( q );
				if ( q.length < 3 || isId ) {
					// Emptied input or an already-picked OSM id: clear stale
					// suggestions so a later retype starts fresh.
					if ( q === '' || isId ) {
						combo.setOptions( [] );
						combo.getMenu().toggle( false );
					}
					return;
				}
				if ( pending ) {
					pending.abort();
				}
				var controller = new AbortController();
				pending = controller;
				var params = new URLSearchParams( {
					q: q,
					format: 'jsonv2',
					limit: '8',
					'accept-language': mw.config.get( 'wgUserLanguage' ) || 'en'
				} );
				fetch( 'https://nominatim.openstreetmap.org/search?' + params.toString(), {
					signal: controller.signal
				} ).then( function ( resp ) {
					return resp.json();
				} ).then( function ( results ) {
					if ( pending !== controller ) {
						return; // a newer search superseded this one
					}
					var options = ( results || [] ).map( function ( row ) {
						return {
							data: row.osm_type + '/' + row.osm_id,
							label: row.display_name
						};
					} );
					combo.setOptions( options );
					combo.getMenu().toggle( options.length > 0 );
				} ).catch( function () {
					// Non-fatal: the user can still type; the server-side
					// OsmPlace validation gates the submitted value.
				} );
			}, 400 ) );

			// The menu hands us the picked option directly: keep the label
			// in sync with the id as the user picks suggestions (typing a
			// raw id afterwards clears it via the change handler below).
			combo.getMenu().on( 'choose', function ( item ) {
				if ( !item || item.getData === undefined ) {
					return;
				}
				var data = String( item.getData() );
				var label = item.getLabel ? String( item.getLabel() ) : '';
				if ( /^(node|way|relation)\/\d+$/i.test( data ) ) {
					lastPicked = data;
					setLabel( label );
				}
			} );

			// A change NOT produced by a menu pick (typed text, a cleared
			// input) invalidates the stored label — never submit a stale
			// display name for a different (or missing) id.
			combo.on( 'change', function ( value ) {
				var q = String( value || '' ).trim();
				if ( q !== lastPicked ) {
					lastPicked = '';
					setLabel( '' );
				}
			} );
		} );
	} );
}() );
