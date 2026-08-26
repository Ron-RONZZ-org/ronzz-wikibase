/**
 * File: search + pick for the "reuse an existing file on this wiki" image
 * option (Add* portrait/logo sections, mode=existing).
 *
 * The server renders the field as an OOUI ComboBoxInputWidget carrying the
 * cssclass `wb-file-combobox` (ImageUploadHelper::existingField); this
 * module wires it to the instance's OWN File: namespace search:
 *
 *  - typing queries `action=query&generator=search&gsrnamespace=6` (the
 *    File: namespace) with imageinfo thumbnails (`iiurlwidth=64`) — each
 *    suggestion shows the file's thumbnail + title;
 *  - picking a suggestion fills the combobox with "File:<title>" (the
 *    server validates the file exists in beforeCreate) and renders a 220px
 *    preview into the field's `.wb-file-preview` slot;
 *  - a typed (or autofilled) "File:<title>" value fetches its preview too.
 *
 * The submitted value is a file TITLE, never a URL — the image/license
 * statements and the classic-page infobox parameter are built server-side
 * from the same FileTitle the upload paths use.
 *
 * Wiring conventions mirror entitysuggest.js: the widget element is
 * targeted via its OOUI class (`.wb-file-combobox.oo-ui-comboBoxInputWidget`,
 * NOT the FieldLayout wrapper), the text input is `combo.$input`, and the
 * instance's search is queried with case-variant fallbacks (T242644).
 */
( function () {
	'use strict';

	/** The widget's text input element (the OOUI ComboBoxInputWidget's). */
	function inputEl( combo ) {
		return combo.$input ? combo.$input[ 0 ] : null;
	}

	/**
	 * Queries the File: namespace for a fragment and returns the hits as
	 * suggestion data: [{ title: "File:…", thumb: "https://…" }]. The raw
	 * query plus a title-cased variant run in parallel and are merged
	 * (deduped) — the instance's search is case-sensitive for the term
	 * store, so "national geographic" must still hit "National Geographic".
	 */
	function searchFiles( q ) {
		var api = new mw.Api();
		var queries = [ q ];
		var tc = q.replace( /(^|\s)(\S)/g, function ( m, pre, ch ) {
			return pre + ch.toUpperCase();
		} );
		if ( tc !== q ) {
			queries.push( tc );
		}
		return Promise.all( queries.map( function ( sq ) {
			return api.get( {
				action: 'query',
				generator: 'search',
				gsrsearch: sq,
				gsrnamespace: 6,
				gsrlimit: 8,
				prop: 'imageinfo',
				iiprop: 'url',
				iiurlwidth: 64,
				format: 'json',
				formatversion: 2
			} );
		} ) ).then( function ( results ) {
			var seen = {};
			var out = [];
			( results || [] ).forEach( function ( data ) {
				( data.query && data.query.pages || [] ).forEach( function ( page ) {
					if ( seen[ page.title ] ) {
						return;
					}
					seen[ page.title ] = true;
					out.push( {
						title: page.title,
						thumb: page.imageinfo && page.imageinfo[ 0 ]
							? page.imageinfo[ 0 ].thumburl || null
							: null
					} );
				} );
			} );
			return out;
		} );
	}

	/**
	 * Renders a preview of a "File:…" value into the field's
	 * .wb-file-preview slot (a 220px thumbnail via imageinfo; a plain
	 * title link when the file is not an image).
	 */
	function updatePreview( combo, value ) {
		var $preview = $( combo.$element ).closest( '.oo-ui-fieldLayout' )
			.find( '.wb-file-preview' ).first();
		if ( !$preview.length ) {
			return;
		}
		$preview.empty();
		if ( !value ) {
			return;
		}
		var title = String( value ).trim();
		if ( title !== '' && !/^File:/i.test( title ) ) {
			title = 'File:' + title;
		}
		new mw.Api().get( {
			action: 'query',
			titles: title,
			prop: 'imageinfo',
			iiprop: 'url',
			iiurlwidth: 220,
			format: 'json',
			formatversion: 2
		} ).then( function ( data ) {
			var page = data.query && data.query.pages && data.query.pages[ 0 ];
			if ( !page ) {
				return;
			}
			var info = page.imageinfo && page.imageinfo[ 0 ];
			if ( info && info.thumburl ) {
				$preview.append(
					$( '<a>' ).attr( 'href', mw.util.getUrl( page.title ) )
						.append( $( '<img>' ).attr( 'src', info.thumburl ).attr( 'alt', page.title ) )
				);
			} else if ( page.title ) {
				$preview.append(
					$( '<a>' ).attr( 'href', mw.util.getUrl( page.title ) ).text( page.title )
				);
			}
		} ).catch( function () {
			// Non-fatal: the typed title still round-trips to the server,
			// which validates it.
		} );
	}

	function wire( $el ) {
		if ( $el.data( 'wbFileCombobox' ) ) {
			return;
		}
		$el.data( 'wbFileCombobox', true );
		var combo = OO.ui.ComboBoxInputWidget.static.infuse( $el );
		var pending = null;

		combo.on( 'change', OO.ui.debounce( function ( value ) {
			var q = String( value || '' ).trim();
			if ( q.length < 2 || /^File:/i.test( q ) ) {
				if ( q.length >= 2 ) {
					// A full "File:<name>" value (picked or typed): show its
					// preview, no suggestions.
					combo.setOptions( [] );
					combo.getMenu().toggle( false );
					updatePreview( combo, q );
				}
				return;
			}
			if ( pending ) {
				Array.isArray( pending ) ? pending.forEach( function ( r ) { r.abort(); } ) : pending.abort();
			}
			var req = searchFiles( q );
			pending = req;
			req.then( function ( hits ) {
				var options = hits.map( function ( hit ) {
					var $label = $( '<span>' );
					if ( hit.thumb ) {
						$label.append( $( '<img>' ).attr( 'src', hit.thumb ).attr( 'width', 32 ).attr( 'height', 32 ) );
					}
					$label.append( document.createTextNode( ' ' + hit.title ) );
					return { data: hit.title, label: $label };
				} );
				combo.setOptions( options );
				combo.getMenu().toggle( options.length > 0 );
			} ).catch( function () {
				// Non-fatal: the combobox still accepts a typed File: title,
				// which the server-side parse validates.
			} );
		}, 250 ) );

		combo.getMenu().on( 'choose', function ( item ) {
			if ( !item ) {
				return;
			}
			combo.setValue( String( item.getData() ) );
			combo.setOptions( [] );
			combo.getMenu().toggle( false );
			updatePreview( combo, String( item.getData() ) );
		} );
	}

	function wireAll() {
		$( '.wb-file-combobox.oo-ui-comboBoxInputWidget' ).each( function () {
			wire( $( this ) );
		} );
	}

	mw.loader.using( 'oojs-ui' ).then( function () {
		wireAll();
		// The mode radio defaults to NOTHING selected, so the OOUI hide-if
		// removes the combobox from the DOM until the user picks
		// "existing" — re-wire any combobox that appears later (idempotent).
		if ( typeof MutationObserver !== 'undefined' ) {
			new MutationObserver( function () {
				wireAll();
			} ).observe( document.body, { childList: true, subtree: true } );
		}
	} );
}() );
