/**
 * OpenStreetMap place search for the person place-of-birth/death comboboxes
 * (osm-places feature) and the legal-text territorial-jurisdiction field
 * (Zotero-aligned batch).
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
 * label sibling (`wpplaceOfBirthOsmLabel` / `wpplaceOfDeathOsmLabel` /
 * `wpterritorialJurisdictionLabel`) — the server's parallel
 * `… (label)` string statement, so the rendered cell shows the
 * human-readable label, not the raw id.
 *
 * MULTI-value fields (the extra cssclass `wb-osm-combobox-multi`, the
 * territorial jurisdiction): the value is a comma-separated id list and the
 * hidden label sibling holds a JSON object mapping each id to its display
 * name. Picking appends to the list (the trailing query segment is replaced,
 * previous picks kept, duplicates dropped) and records the label; a stale
 * map entry for a removed id is harmless (the server prunes it on submit).
 *
 * Network failures are non-fatal — the server rejects an unpicked name on
 * submit with a clear error.
 */
( function () {
	'use strict';

	function isOsmId( value ) {
		return /^(node|way|relation)\/\d+$/i.test( String( value || '' ).trim() );
	}

	function splitList( value ) {
		return String( value || '' ).split( /[,;]\s*/ ).map( function ( segment ) {
			return String( segment || '' ).trim();
		} ).filter( function ( segment ) {
			return segment !== '';
		} );
	}

	mw.loader.using( 'oojs-ui' ).then( function () {
		// Target the widget element itself (its OOUI class), not the
		// FieldLayout wrapper — same pattern as entitysuggest.js.
		$( '.wb-osm-combobox.oo-ui-comboBoxInputWidget' ).each( function () {
			var $el = $( this );
			var multi = $el.hasClass( 'wb-osm-combobox-multi' );
			var combo = OO.ui.ComboBoxInputWidget.static.infuse( this );
			var pending = null; // AbortController of the in-flight search
			// The label field is the hidden sibling named after the OSM id
			// field: placeOfBirthOsm → wpplaceOfBirthOsmLabel. Resolve it at
			// event time (never at wiring time) — the OOUI hide-if may
			// remove/re-insert the death fields when the deceased toggle
			// flips.
			var name = String( combo.$input.attr( 'name' ) || '' );
			var labelName = name ? name + 'Label' : '';
			var lastPicked = ''; // single mode: the id whose label is stored

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

			function readLabelMap() {
				var input = labelInput();
				if ( !input ) {
					return {};
				}
				try {
					var parsed = JSON.parse( input.value || '{}' );
					return ( parsed && typeof parsed === 'object' && !Array.isArray( parsed ) ) ? parsed : {};
				} catch ( e ) {
					return {};
				}
			}

			function writeLabelMap( map ) {
				var input = labelInput();
				if ( input ) {
					input.value = Object.keys( map ).length ? JSON.stringify( map ) : '';
				}
			}

			function runSearch( query ) {
				var q = String( query || '' ).trim();
				if ( q.length < 3 || isOsmId( q ) ) {
					// Emptied input or an already-picked OSM id: clear stale
					// suggestions so a later retype starts fresh.
					if ( q === '' || isOsmId( q ) ) {
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
			}

			// Search on the LAST comma/semicolon-separated segment for
			// multi-value fields (what the user is typing).
			combo.on( 'change', OO.ui.debounce( function ( value ) {
				runSearch( multi ? splitList( value ).pop() : String( value || '' ).trim() );
			}, multi ? 250 : 400 ) );

			// The raw user-typed value, BEFORE OOUI's own pick overwrites the
			// input (a native 'input' listener does not fire on a programmatic
			// setValue). Needed to rebuild the multi-value list on pick.
			var lastUserValue = String( combo.$input.val() || '' );
			combo.$input.on( 'input', function () {
				lastUserValue = String( combo.$input.val() || '' );
			} );

			// The menu hands us the picked option directly: keep the label
			// in sync with the id as the user picks suggestions.
			combo.getMenu().on( 'choose', function ( item ) {
				if ( !item || item.getData === undefined ) {
					return;
				}
				var data = String( item.getData() );
				var label = item.getLabel ? String( item.getLabel() ) : '';
				if ( !isOsmId( data ) ) {
					return;
				}
				if ( multi ) {
					// Keep the previously-picked ids, drop the trailing query
					// segment, append the pick (duplicates dropped).
					var picked = splitList( lastUserValue ).filter( isOsmId );
					if ( picked.indexOf( data ) === -1 ) {
						picked.push( data );
					}
					combo.setValue( picked.join( ', ' ) );
					lastUserValue = picked.join( ', ' );
					var map = readLabelMap();
					map[ data ] = label;
					writeLabelMap( map );
					return;
				}
				lastPicked = data;
				setLabel( label );
			} );

			if ( !multi ) {
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
			}
		} );
	} );
}() );
