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
 *
 * ⚠️ Binding must be DELEGATED (document-level), not per-banner at module
 * load: the OOUI HTMLForm marks its field layouts `mw-htmlform-autoinfuse`
 * and re-creates them client-side from their `data-ooui` config (which
 * embeds the help HTML, banner included). A handler attached to the
 * server-rendered banner node at load time is orphaned when OOUI replaces
 * that node — the visible banner ends up with no live handlers and both
 * buttons appear dead (the AddPerson OSM place-of-birth confirm report).
 * Delegation resolves the banner from the CLICKED button at event time, so
 * it works whether the banner is the original server node, a re-created
 * infusion node, or one inserted later (hide-if toggles, uploadmeta.js).
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

	/**
	 * Clears a field and focuses it, keeping an OOUI widget in sync: OOUI's
	 * TextInputWidget re-syncs its internal value on native change/input
	 * events (the uploadmeta.js `fieldVal().set()` pattern). Setting the DOM
	 * value alone would leave the widget holding the old value, so a
	 * [No, let me correct] would appear to "not clear" the prefilled id.
	 */
	function clearAndFocus( input ) {
		input.value = '';
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.focus();
	}

	$( document ).on( 'click', '.wb-entity-confirm-yes', function ( e ) {
		// Keep the prefilled value, dismiss the banner.
		$( e.target ).closest( '.wb-entity-confirm' ).remove();
	} );

	$( document ).on( 'click', '.wb-entity-confirm-no', function ( e ) {
		var $banner = $( e.target ).closest( '.wb-entity-confirm' );
		var input = fieldInput( String( $banner.data( 'field' ) || '' ) );
		if ( input ) {
			clearAndFocus( input );
		}
		$banner.remove();
	} );
}() );
