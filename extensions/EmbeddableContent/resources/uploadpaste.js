/**
 * Early clipboard-paste / drag-drop intake for Special:Upload.
 *
 * The page renders the hint "Drag an image here, or paste it from your
 * clipboard" as soon as the form is parsed, but the heavier upload modules
 * (ext.embeddableContent.uploadmeta → oojs-ui, uploadimage) can take seconds
 * to arrive on a cold ResourceLoader cache. This module has NO ResourceLoader
 * dependencies, so it executes as soon as its own file is fetched and owns the
 * intake, so a paste made during that window is not silently dropped (the
 * reported "paste works only some of the time").
 *
 * Filling the picker needs nothing from those modules: switch the source radio
 * to File, enable the file input EXPLICITLY (core's mediawiki.special.upload
 * radio handler may not be bound yet), put the bytes into a DataTransfer and
 * fire `change`. resources/uploadimage.js renders the preview/resize when it
 * finishes loading — including a file that was already placed in the input.
 *
 * resources/uploadform.js keeps the submission/enhancement logic; this module
 * is the single owner of the paste/drop intake on Special:Upload.
 */
( function () {
	'use strict';

	/** A file pasted before the form was parsed, filled at DOM ready. */
	var pendingFile = null;
	var flushScheduled = false;

	/** Resolve a form field NAME to its real <input> (php-mode id, an OOUI
	 * wrapper's inner input, or a name lookup — the uploadform.js contract). */
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

	/** The Source-filename <input type=file>, or null. */
	function fileInput() {
		var input = findInput( 'wpUploadFile' );
		return input && input.type === 'file' ? input : null;
	}

	/** The first image file in a FileList, or null (falls back to the first
	 * file at all: a pasted blob may arrive with an empty type). */
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
		return fileList.length > 0 ? fileList[ 0 ] : null;
	}

	/**
	 * The file from a ClipboardEvent's clipboardData, or null. Some browsers
	 * leave `files` empty for an image paste and expose the bytes only through
	 * `items` (`kind: 'file'`), so check both.
	 */
	function clipboardFile( clipboard ) {
		if ( !clipboard ) {
			return null;
		}
		var fromFiles = firstImageFile( clipboard.files );
		if ( fromFiles ) {
			return fromFiles;
		}
		var items = clipboard.items || [];
		for ( var i = 0; i < items.length; i++ ) {
			var item = items[ i ];
			if ( item && item.kind === 'file' && item.getAsFile ) {
				var fromItem = item.getAsFile();
				if ( fromItem ) {
					return fromItem;
				}
			}
		}
		return null;
	}

	/**
	 * Select a Source radio and keep a hidden value input in sync (the OOUI
	 * forms' RadioSelect submits through the hidden input). Core's
	 * mediawiki.special.upload handler enables the selected field when it
	 * catches the change; callers must not rely on that.
	 */
	function selectSourceType( value ) {
		var radio = document.getElementById( 'wpSourceType' + value );
		if ( radio && !radio.checked ) {
			radio.checked = true;
			radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		var hidden = document.querySelector(
			'input[name="wpSourceType"]:not([type="radio"]):not([type="checkbox"])'
		);
		if ( hidden ) {
			hidden.value = value;
		}
	}

	/** A non-fatal notification; mediawiki.notification is loaded on demand
	 * so this module stays dependency-free. */
	function notify( msgKey ) {
		mw.loader.using( 'mediawiki.notification' ).then( function () {
			mw.notify( mw.msg( msgKey ) );
		}, function () {
			// The module failed to load; never swallow silently.
			if ( window.console && console.warn ) {
				console.warn( 'EmbeddableContent upload: ' + msgKey );
			}
		} );
	}

	function scheduleFlush() {
		if ( flushScheduled ) {
			return;
		}
		flushScheduled = true;
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', flush, { once: true } );
		} else {
			flush();
		}
	}

	/** Fill the form with a file; queue it when the body is not parsed yet. */
	function fill( file ) {
		if ( !file ) {
			return false;
		}
		selectSourceType( 'File' );
		var input = fileInput();
		if ( !input ) {
			pendingFile = file;
			scheduleFlush();
			return false;
		}
		pendingFile = null;
		// Do not depend on core's radio-change handler having run: a disabled
		// control is excluded from FormData, so an un-enabled input would
		// silently drop the pasted file on submit.
		input.disabled = false;
		input.removeAttribute( 'disabled' );
		try {
			var dt = new DataTransfer();
			dt.items.add( file );
			input.files = dt.files;
		} catch ( e ) {
			notify( 'embeddablecontent-upload-drop-unsupported' );
			return false;
		}
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		return true;
	}

	function flush() {
		flushScheduled = false;
		if ( !pendingFile || !fileInput() ) {
			return;
		}
		var file = pendingFile;
		fill( file );
	}

	// 1. Paste anywhere on the page. A paste event fires on the focused
	// element (the body on a fresh load) and bubbles from there; a text paste
	// falls through (only a clipboard file triggers the upload).
	document.addEventListener( 'paste', function ( e ) {
		// An open image-editor dialog owns the file while it is up.
		if ( document.querySelector( '.oo-ui-windowManager-modal-active .oo-ui-dialog' ) ) {
			return;
		}
		var clipboard = e.clipboardData ||
			( e.originalEvent && e.originalEvent.clipboardData );
		var file = clipboardFile( clipboard );
		if ( file ) {
			e.preventDefault();
			fill( file );
		}
	} );

	// 2. Drop on the Source-filename row (or its help/hint row below it).
	// Delegated from the document so it also works before the form is parsed.
	function dropRow( target ) {
		var input = fileInput();
		if ( !input || !target ) {
			return null;
		}
		var row = input.closest ? input.closest( 'tr' ) : null;
		if ( !row ) {
			return input.parentNode;
		}
		var help = row.nextElementSibling;
		if ( help && help.classList && help.classList.contains( 'htmlform-tip' ) &&
			help.contains( target )
		) {
			return help;
		}
		return row.contains( target ) ? row : null;
	}

	document.addEventListener( 'dragover', function ( e ) {
		var row = dropRow( e.target );
		if ( !row ) {
			return;
		}
		e.preventDefault();
		if ( e.dataTransfer ) {
			e.dataTransfer.dropEffect = 'copy';
		}
		row.classList.add( 'wb-upload-drop-active' );
	} );

	document.addEventListener( 'dragleave', function ( e ) {
		var row = dropRow( e.target );
		if ( row ) {
			row.classList.remove( 'wb-upload-drop-active' );
		}
	} );

	document.addEventListener( 'drop', function ( e ) {
		var row = dropRow( e.target );
		if ( !row ) {
			return;
		}
		row.classList.remove( 'wb-upload-drop-active' );
		var file = firstImageFile( e.dataTransfer && e.dataTransfer.files );
		if ( file ) {
			e.preventDefault();
			fill( file );
		}
	} );

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.uploadPaste = {
		fill: fill,
		firstImageFile: firstImageFile,
		clipboardFile: clipboardFile
	};
}() );
