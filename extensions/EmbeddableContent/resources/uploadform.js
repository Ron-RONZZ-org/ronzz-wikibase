/**
 * Special:Upload form behaviour (2026-09 UX batch):
 *
 *  1. Source gating — core's mediawiki.special.upload only sets the initial
 *     disabled state for the URL field; when Url is the selected source
 *     (this instance's fresh-load default) the FILE picker must be disabled
 *     too. Core's own radio-change handler keeps both in sync afterwards.
 *  2. Empty Author / License warning — on submit, warn (cancellable) when
 *     either field is empty; the user may still upload.
 *  3. "Submit and upload another image from same author" — run the upload in
 *     a NEW TAB so the File: page lands there, leaving this tab to be
 *     reloaded by that page's opener signal (resources/filepage.js, fed by
 *     the BeforePageRedirect hand-off). window.open keeps the opener
 *     relationship (a formtarget=_blank form may drop it in some browsers).
 */
( function () {
	'use strict';

	/**
	 * Resolve an HTMLForm field NAME to its real <input> (php-mode id, an
	 * OOUI widget wrapper's inner input, or a name lookup) — the
	 * uploadmeta.js findInput contract.
	 */
	function findInput( name ) {
		var el = document.getElementById( name );
		if ( el && el.tagName === 'INPUT' ) {
			return el;
		}
		if ( el ) {
			var inner = el.querySelector( 'input' );
			if ( inner ) {
				return inner;
			}
		}
		return document.querySelector( 'input[name="' + name + '"]' ) || null;
	}

	function fieldValue( name ) {
		var el = findInput( name );
		return el ? String( el.value || '' ) : '';
	}

	mw.loader.using( 'mediawiki.notification' ).then( function () {
		var $form = $( '#mw-upload-form' );
		if ( $form.length === 0 ) {
			return;
		}

		// 1. Source-field gating (initial state; core keeps it in sync after
		// a radio change).
		var $file = $( '#wpUploadFile' );
		var $fileRadio = $( '#wpSourceTypeFile' );
		if ( $file.length && $fileRadio.length ) {
			$file.prop( 'disabled', !$fileRadio.prop( 'checked' ) );
		}

		// 2 + 3. Submit-button click: warn first, then wire the new tab.
		// Attached to the BUTTONS' click (runs before the submit event), so
		// a cancelled warning prevents the whole submission — including the
		// uploadmeta Wikimedia-blob resubmit (a submit-event handler could
		// be bypassed by uploadmeta's async form.submit()).
		$form.on( 'click', 'input[type="submit"], button[type="submit"]', function ( e ) {
			// The warning-recovery form's Cancel/Re-upload button must never
			// be blocked by the empty-field warning.
			var buttonName = this.name || '';
			if ( buttonName === 'wpCancelUpload' || buttonName === 'wpReUpload' ) {
				return;
			}
			var missing = [];
			if ( !fieldValue( 'wpUploadAuthor' ).trim() ) {
				missing.push( mw.msg( 'embeddablecontent-upload-author' ) );
			}
			if ( !fieldValue( 'wpLicense' ).trim() ) {
				missing.push( mw.msg( 'embeddablecontent-upload-license' ) );
			}
			if ( missing.length > 0 &&
				!window.confirm( mw.msg( 'embeddablecontent-upload-missing-confirm', missing.join( ', ' ) ) )
			) {
				e.preventDefault();
				return;
			}
			if ( this.id === 'wpUploadAnother' ) {
				var w = window.open( '', 'wbuploadanother' );
				if ( w ) {
					$form.attr( 'target', 'wbuploadanother' );
				}
			}
		} );
	} );
}() );
