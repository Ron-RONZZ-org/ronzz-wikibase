/**
 * Defensive date-field normalization for the Add* / Update* forms.
 *
 * MediaWiki's OOUI DateInputWidget keeps a value it only LOOSELY parses
 * verbatim: a non-`YYYY-MM-DD` string that moment accepts (e.g. a raw
 * Wikibase time "+1970-01-01T00:00:00Z") is submitted unchanged, the field
 * DISPLAYS the parsed date ("Thu, 1 Jan 1970"), and the server's
 * HTMLDateTimeField then rejects it ("The value you specified is not a
 * recognized date. Try using YYYY-MM-DD format." — the AddPerson
 * date-of-birth report). The widget's own setValue() only rejects values
 * moment considers invalid; it never normalizes a loosely-valid one.
 *
 * This module rewrites every infused date widget's value to the strict
 * `YYYY-MM-DD` form its display already implies, on form submit. It is a
 * no-op for an already-canonical value and clears a value that cannot be
 * parsed at all (never posting an unparseable string).
 */
( function () {
	'use strict';

	var CANONICAL = /^\d{4}-\d{2}-\d{2}$/;

	function normalizeWidget( el ) {
		var widget;
		try {
			widget = OO.ui.Widget.static.infuse( $( el ) );
		} catch ( e ) {
			return;
		}
		if ( !widget || typeof widget.getValue !== 'function' ) {
			return;
		}
		var value = widget.getValue();
		if ( value === '' || CANONICAL.test( value ) ) {
			return;
		}
		var moment = typeof widget.getMoment === 'function' ? widget.getMoment() : null;
		if ( moment && moment.isValid() ) {
			widget.setValue( moment.format( 'YYYY-MM-DD' ) );
		} else {
			widget.setValue( '' );
		}
	}

	$( function () {
		$( document ).on( 'submit', 'form', function () {
			$( this ).find( '.mw-widget-dateInputWidget' ).each( function () {
				normalizeWidget( this );
			} );
		} );
	} );
}() );
