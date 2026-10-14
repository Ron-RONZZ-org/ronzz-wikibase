/**
 * "Preview" button for the content-page append editor
 * (action=appendsection / "Add section" and "Add section below").
 *
 * The server renders the editor with the plain wikitext textarea
 * (`#wpTextbox1`, so WikiEditor attaches the same toolbar as the ordinary
 * edit page). This module injects a Preview button into the form's submit row
 * and a preview box above it, and renders the boxed wikitext through
 * `action=parse` (the server is the wikitext renderer — links/media/templates
 * behave exactly as on the page), matching the existing special-page preview
 * primitives (addmath.js, addsource-labelpreview.js). The parsed result is
 * the content the editor is about to append.
 *
 * Loaded only on the append action (the module is added by AppendSectionAction).
 */
( function () {
	'use strict';

	function contentTextarea() {
		var $byId = $( 'textarea#wpTextbox1' );
		if ( $byId.length ) {
			return $byId;
		}
		return $( 'textarea[name="wpcontent"]' ).first();
	}

	$( function () {
		var $ta = contentTextarea();
		var $submit = $( '.mw-htmlform-submit' ).first();
		if ( !$ta.length || !$submit.length ) {
			return;
		}

		var $box = $( '<div class="wb-sectionform-preview"></div>' ).prop( 'hidden', true );
		var $btn = $( '<button>' )
			.attr( 'id', 'wb-sectionform-preview' )
			.attr( 'type', 'button' )
			.addClass( 'wb-sectionform-preview-btn' )
			.text( mw.msg( 'embeddablecontent-sectionform-preview' ) );

		function showError() {
			$box.text( mw.msg( 'embeddablecontent-sectionform-preview-error' ) )
				.addClass( 'wb-sectionform-preview-error' )
				.prop( 'hidden', false );
		}

		$btn.on( 'click', function () {
			var text = String( $ta.val() || '' );
			if ( text.trim() === '' ) {
				$box.text( mw.msg( 'embeddablecontent-sectionform-preview-empty' ) )
					.removeClass( 'wb-sectionform-preview-error' )
					.prop( 'hidden', false );
				return;
			}
			new mw.Api().post( {
				action: 'parse',
				contentmodel: 'wikitext',
				prop: 'text',
				disablelimitreport: 1,
				disableeditsection: 1,
				disabletoc: 1,
				text: text
			} ).done( function ( data ) {
				var html = data && data.parse && data.parse.text && data.parse.text[ '*' ];
				if ( typeof html !== 'string' ) {
					showError();
					return;
				}
				$box.removeClass( 'wb-sectionform-preview-error' ).html( html ).prop( 'hidden', false );
			} ).fail( showError );
		} );

		// Preview button first in the submit row; the box above it.
		$submit.prepend( $btn );
		$submit.before( $box );
	} );
}() );
