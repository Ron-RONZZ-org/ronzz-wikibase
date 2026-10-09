/**
 * Special:Upload form behaviour:
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
 *     the BeforePageRedirect hand-off). The SOURCE radio choice rides the
 *     hand-off too (wbsourcetype), so a URL uploader pastes another URL
 *     instead of re-picking a file.
 *  4. Drag-and-drop / clipboard paste — dropping an image onto the source
 *     file area (or pasting one) switches the source to "Source filename"
 *     and fills the file input, firing `change` so the shared
 *     resources/uploadimage.js preview + resize run.
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

	/** The current Source-filename <input type=file>, or null. */
	function fileInput() {
		var input = findInput( 'wpUploadFile' );
		return input && input.type === 'file' ? input : null;
	}

	/** The first image file in a FileList/DataTransfer, or null. */
	function firstImageFile( fileList ) {
		if ( !fileList ) {
			return null;
		}
		for ( var i = 0; i < fileList.length; i++ ) {
			var file = fileList[ i ];
			if ( file && file.type && file.type.indexOf( 'image/' ) === 0 ) {
				return file;
			}
		}
		// Fall back to the first file at all (a pasted SVG/blob may arrive
		// with an empty type in some browsers).
		return fileList.length > 0 ? fileList[ 0 ] : null;
	}

	/** Select the "Source filename" (File) radio, letting core reveal the picker. */
	function switchToFileMode() {
		var radio = document.getElementById( 'wpSourceTypeFile' );
		if ( radio && !radio.checked ) {
			radio.checked = true;
			// Core's mediawiki.special.upload radio handler syncs the URL /
			// file field states; fire it so the file input is enabled.
			radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	/** Put `file` into the file input and notify the shared modules. */
	function setFile( file ) {
		var input = fileInput();
		if ( !input ) {
			return;
		}
		switchToFileMode();
		try {
			var dt = new DataTransfer();
			dt.items.add( file );
			input.files = dt.files;
		} catch ( e ) {
			// A browser without a settable FileList (old Safari) — leave the
			// picker for the user rather than silently doing nothing.
			mw.notify( mw.msg( 'embeddablecontent-upload-drop-unsupported' ) );
			return;
		}
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
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

		// 4. Drag-and-drop onto the source file area. The input may be
		// disabled (Url mode) — bind to its field wrapper so a drop is
		// accepted anywhere over the "Source filename" row.
		var fileEl = fileInput();
		if ( fileEl ) {
			var $drop = $( fileEl ).closest( '.mw-htmlform-field, .oo-ui-fieldLayout, tr' );
			if ( $drop.length === 0 ) {
				$drop = $( fileEl );
			}
			$drop.on( 'dragover', function ( e ) {
				e.preventDefault();
				e.originalEvent.dataTransfer.dropEffect = 'copy';
				$drop.addClass( 'wb-upload-drop-active' );
			} );
			$drop.on( 'dragleave drop', function () {
				$drop.removeClass( 'wb-upload-drop-active' );
			} );
			$drop.on( 'drop', function ( e ) {
				e.preventDefault();
				var file = firstImageFile( e.originalEvent.dataTransfer.files );
				if ( file ) {
					setFile( file );
				}
			} );
		}

		// 4b. Clipboard paste (an image on the clipboard) anywhere in the
		// upload form — a text paste keeps working (only a file triggers it).
		$form.on( 'paste', function ( e ) {
			var clipboard = e.originalEvent.clipboardData;
			var file = clipboard && firstImageFile( clipboard.files );
			if ( file ) {
				e.preventDefault();
				setFile( file );
			}
		} );
	} );
}() );
