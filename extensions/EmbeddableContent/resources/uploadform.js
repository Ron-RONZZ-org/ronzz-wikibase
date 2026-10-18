/**
 * Special:Upload form behaviour:
 *
 *  1. Source gating — core's mediawiki.special.upload only sets the initial
 *     disabled state for the URL field; when Url is the selected source
 *     (this instance's fresh-load default) the FILE picker must be disabled
 *     too. Core's own radio-change handler keeps both in sync afterwards.
 *  2. Empty Author / License warning — on submit, warn (cancellable) when
 *     either field is empty; the user may still upload.
 *  3. Upload submission through a SAME-ORIGIN `fetch` POST in THIS tab.
 *     The form is never submitted into a newly opened top-level window (a
 *     fresh blank tab can lose the MediaWiki session) and never into a
 *     hidden iframe (Special:Upload responses carry X-Frame-Options: DENY,
 *     so the iframe cannot load either the error page or the File: page).
 *     The fetch keeps the POST in the current page's session, so on failure
 *     the page does not navigate and the file input, author, license and
 *     every other field stay intact. On success the current tab goes to the
 *     File: page (normal "Upload") or the pre-opened destination tab does
 *     ("upload another").
 *
 *     "Submit and upload another image from same author" additionally opens a
 *     DESTINATION TAB on click (the user gesture guarantees it is not blocked).
 *     Success navigates that tab to the File: page, whose filepage.js opener
 *     hand-off resets THIS tab to a fresh prefilled form for the next image.
 *     Failure closes the destination tab and shows the server's error inline
 *     here; a stale CSRF token is refreshed so the user can retry immediately.
 *     A duplicate/exists warning falls back to the normal warning page.
 *
 *     uploadmeta.js's Wikimedia URL → browser-blob conversion calls the
 *     shared mw.embeddableContent.upload.submitConverted() hook instead of
 *     natively submitting the form, so the converted file also rides the
 *     fetch POST.
 *
 *  4. Drag-and-drop / clipboard paste — dropping an image onto the source
 *     file area (or pasting one) switches the source to "Source filename"
 *     and fills the file input, firing `change` so the shared
 *     resources/uploadimage.js preview + resize run.
 */
( function () {
	'use strict';

	var DESTINATION_WINDOW_NAME = 'wbuploadanother';

	/** The form being enhanced, resolved once on DOM ready. */
	var $form = null;
	var $error = null;
	var $status = null;
	var formAction = '';

	/** State of the fetch submission. */
	var uploadInProgress = false;
	var conversionInProgress = false;
	var uploadmetaFailed = false;
	var fetchInProgress = false;
	var pendingDestinationWindow = null;
	var lastSubmitter = null;

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

	/** A Codex error message box (same markup Html::errorBox() uses). */
	function makeMessageBox( text ) {
		return $( '<div>' )
			.addClass( 'cdx-message cdx-message--error' )
			.append(
				$( '<div>' )
					.addClass( 'cdx-message__content' )
					.text( text )
			);
	}

	function showError( content ) {
		$error.empty().append( content ).show();
		$error.attr( 'tabindex', '-1' ).trigger( 'focus' );
	}

	function clearError() {
		$error.empty().hide();
	}

	function setStatus( text ) {
		if ( text ) {
			$status.text( text ).show();
		} else {
			$status.text( '' ).hide();
		}
	}

	/** @return {boolean} whether `url` has the Special:Upload path. */
	function isUploadUrl( url ) {
		try {
			return new URL( url, window.location.href ).pathname ===
				new URL( formAction, window.location.href ).pathname;
		} catch ( e ) {
			return false;
		}
	}

	function isLoginUrl( url ) {
		try {
			var u = new URL( url, window.location.href );
			return /Special:UserLogin/.test( u.pathname ) ||
				/[?&]title=Special:UserLogin/.test( u.search );
		} catch ( e ) {
			return false;
		}
	}

	/** Refresh the form's CSRF token from the current session. */
	function refreshToken() {
		return new mw.Api().get( {
			action: 'query',
			meta: 'tokens',
			type: 'csrf'
		} ).then( function ( data ) {
			var token = data && data.query && data.query.tokens &&
				data.query.tokens.csrftoken;
			if ( !token ) {
				throw new Error( 'no csrf token' );
			}
			$( findInput( 'wpEditToken' ) ).val( token );
			return token;
		} );
	}

	/**
	 * The pre-opened destination tab, with a small placeholder so the user
	 * does not see a blank page while the upload runs.
	 */
	function openDestination() {
		var w;
		try {
			w = window.open( '', DESTINATION_WINDOW_NAME );
		} catch ( e ) {
			return null;
		}
		if ( !w ) {
			return null;
		}
		try {
			var message = mw.msg( 'embeddablecontent-upload-uploading' );
			w.document.title = message;
			w.document.body.textContent = message;
			w.document.body.style.fontFamily = 'sans-serif';
			w.document.body.style.padding = '2em';
			w.document.body.style.color = '#202122';
		} catch ( e ) {
			// The tab is still usable even if the placeholder could not be written.
		}
		return w;
	}

	/** Close the destination tab, or leave a readable message if the browser refuses. */
	function closeDestination() {
		var w = pendingDestinationWindow;
		pendingDestinationWindow = null;
		if ( !w || w.closed ) {
			return;
		}
		try {
			w.close();
		} catch ( e ) {
			// Fall through to the message below.
		}
		if ( !w.closed ) {
			try {
				w.document.body.textContent = mw.msg( 'embeddablecontent-upload-close-tab' );
			} catch ( e ) {
				// Cross-origin or already gone — nothing more to do.
			}
		}
	}

	/** Reset everything after an aborted conversion or a failure. */
	function cancelPendingUpload() {
		uploadInProgress = false;
		conversionInProgress = false;
		fetchInProgress = false;
		setStatus( '' );
		closeDestination();
	}

	function finishSuccess( url ) {
		var destination = pendingDestinationWindow;
		pendingDestinationWindow = null;
		uploadInProgress = false;
		conversionInProgress = false;
		fetchInProgress = false;
		setStatus( '' );
		if ( destination && !destination.closed ) {
			try {
				destination.location.href = url;
				return;
			} catch ( e ) {
				// Cross-origin destination — navigate this tab instead.
			}
		}
		window.location.href = url;
	}

	function finishFailure( content ) {
		uploadInProgress = false;
		conversionInProgress = false;
		fetchInProgress = false;
		setStatus( '' );
		closeDestination();
		showError( content );
		// A retry must go back through the fetch handler.
		$form.attr( 'target', '_self' );
	}

	/** @return {boolean} whether the response document is a duplicate/exists warning. */
	function isWarningPage( doc ) {
		return !!doc.querySelector(
			'input[name="wpUploadIgnoreWarning"], .mw-destfile-warning'
		);
	}

	/** @return {string} the first server-rendered error box, or ''. */
	function errorHtmlFrom( doc ) {
		var selectors = [
			'#mw-content-text .cdx-message--error',
			'#mw-content-text .mw-message-box-error',
			'#mw-content-text .errorbox',
			'#mw-content-text .mw-message-box'
		];
		for ( var i = 0; i < selectors.length; i++ ) {
			var el = doc.querySelector( selectors[ i ] );
			if ( el ) {
				return el.outerHTML;
			}
		}
		return '';
	}

	/**
	 * A recoverable warning needs the stock warning page (it carries the
	 * stash key). Re-submit normally: the file is still in the form, and the
	 * server has already stashed it, so nothing is lost.
	 */
	function warningContent() {
		var $box = makeMessageBox( mw.msg( 'embeddablecontent-upload-review-warning' ) );
		var $review = $( '<button>' )
			.attr( 'type', 'button' )
			.addClass( 'wb-upload-review-btn' )
			.text( mw.msg( 'embeddablecontent-upload-review' ) )
			.on( 'click', function () {
				clearError();
				$form.attr( 'target', '_self' );
				// Native submit() bypasses fetch/uploadmeta: the file is
				// already stashed server-side, so the warning page loads
				// without re-fetching or re-converting anything.
				$form[ 0 ].submit();
			} );
		$box.find( '.cdx-message__content' ).append( $review );
		return $box;
	}

	/** Handle the parsed response document of a failed fetch POST. */
	function handleResponseDoc( doc ) {
		if ( !doc || !doc.body ) {
			finishFailure( makeMessageBox(
				mw.msg( 'embeddablecontent-upload-failed-generic' )
			) );
			return;
		}
		if ( doc.body.classList.contains( 'wb-upload-token-failed' ) ) {
			if ( mw.user.isAnon() ) {
				finishFailure( makeMessageBox( mw.msg( 'embeddablecontent-upload-logged-out' ) ) );
				return;
			}
			// The token can be stale after a long-idle form. Refresh it and
			// keep the user's file so a retry is one click away.
			refreshToken().then( function () {
				finishFailure( makeMessageBox(
					mw.msg( 'embeddablecontent-upload-session-refreshed' )
				) );
			}, function () {
				finishFailure( makeMessageBox(
					mw.msg( 'embeddablecontent-upload-failed-generic' )
				) );
			} );
			return;
		}
		if ( isWarningPage( doc ) ) {
			finishFailure( warningContent() );
			return;
		}
		var errorHtml = errorHtmlFrom( doc );
		finishFailure(
			errorHtml || makeMessageBox( mw.msg( 'embeddablecontent-upload-failed-generic' ) )
		);
	}

	/** POST the form with fetch, keeping the page (and file) in place on failure. */
	function startFetchSubmit() {
		if ( fetchInProgress ) {
			return;
		}
		fetchInProgress = true;
		uploadInProgress = true;
		setStatus( mw.msg( 'embeddablecontent-upload-uploading' ) );
		$form.attr( 'target', '_self' );

		var form = $form[ 0 ];
		var data;
		try {
			data = new FormData( form );
		} catch ( e ) {
			finishFailure( makeMessageBox( mw.msg( 'embeddablecontent-upload-failed-generic' ) ) );
			return;
		}

		// FormData omits submit buttons. Add the clicked one (the only way
		// core processes an upload) unless uploadmeta replicated it already.
		var submitter = lastSubmitter ||
			form.querySelector( 'input[type="submit"][name="wpUpload"], button[type="submit"][name="wpUpload"]' );
		if ( submitter && submitter.name ) {
			data.delete( submitter.name );
			data.append( submitter.name, submitter.value || '1' );
		}

		fetch( formAction, {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
			redirect: 'follow'
		} ).then( function ( response ) {
			var url = response.url;
			if ( isLoginUrl( url ) ) {
				finishFailure( makeMessageBox( mw.msg( 'embeddablecontent-upload-logged-out' ) ) );
				return;
			}
			if ( !isUploadUrl( url ) ) {
				// The upload redirects to the File: page on success.
				finishSuccess( url );
				return;
			}
			return response.text().then( function ( html ) {
				var doc = new DOMParser().parseFromString( html, 'text/html' );
				handleResponseDoc( doc );
			} );
		} ).catch( function () {
			finishFailure( makeMessageBox( mw.msg( 'embeddablecontent-upload-failed-generic' ) ) );
		} );
	}

	mw.loader.using( 'mediawiki.notification' ).then( function () {
		$form = $( '#mw-upload-form' );
		if ( $form.length === 0 ) {
			return;
		}
		formAction = $form.attr( 'action' ) || window.location.href;
		$form.attr( 'target', '_self' );

		$error = $( '<div>' )
			.attr( { id: 'wb-upload-error', role: 'alert', 'aria-live': 'assertive' } )
			.addClass( 'wb-upload-error' )
			.hide()
			.prependTo( $form );
		$status = $( '<div>' )
			.attr( { id: 'wb-upload-status', role: 'status', 'aria-live': 'polite' } )
			.addClass( 'wb-upload-status' )
			.hide()
			.insertAfter( $error );

		// uploadmeta.js's async Wikimedia blob conversion must hand the
		// completed form to the fetch path instead of natively submitting it.
		mw.embeddableContent = mw.embeddableContent || {};
		mw.embeddableContent.upload = mw.embeddableContent.upload || {};
		mw.embeddableContent.upload.submitConverted = function () {
			startFetchSubmit();
		};

		// 1. Source-field gating (initial state; core keeps it in sync after
		// a radio change).
		var $file = $( '#wpUploadFile' );
		var $fileRadio = $( '#wpSourceTypeFile' );
		if ( $file.length && $fileRadio.length ) {
			$file.prop( 'disabled', !$fileRadio.prop( 'checked' ) );
		}

		// The form may have been open for a long time; start from a token
		// that is valid for the current session. Failure recovery refreshes
		// it again if the session changes later.
		if ( !mw.user.isAnon() ) {
			refreshToken().catch( function () {} );
		}

		// 2 + 3. Submit-button click: warn first, then open the destination
		// tab for "upload another". The POST itself is the fetch in THIS tab.
		$form.on( 'click', 'input[type="submit"], button[type="submit"]', function ( e ) {
			// The warning-recovery form's Cancel/Re-upload button must never
			// be blocked by the empty-field warning.
			var buttonName = this.name || '';
			if ( buttonName === 'wpCancelUpload' || buttonName === 'wpReUpload' ) {
				// The warning/error form's Cancel/Re-upload buttons restore the
				// form server-side; let that response replace this page normally.
				$form.attr( 'target', '_self' );
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
			if ( uploadInProgress || conversionInProgress ) {
				e.preventDefault();
				return;
			}
			clearError();
			lastSubmitter = this;
			$form.attr( 'target', '_self' );
			uploadmetaFailed = false;
			pendingDestinationWindow = this.id === 'wpUploadAnother' ?
				openDestination() :
				null;
		} );

		// e.preventDefault() stops the native navigation; the fetch above
		// owns the submission. uploadmeta.js may prevent the first submit to
		// convert a Wikimedia URL into a browser-supplied blob; its
		// submitConverted() hook resumes the fetch afterwards.
		$form.on( 'submit', function ( e ) {
			if ( e.isDefaultPrevented() ) {
				conversionInProgress = true;
				return;
			}
			e.preventDefault();
			conversionInProgress = false;
			startFetchSubmit();
		} );

		// uploadmeta.js reports its own conversion errors; close the empty
		// destination tab and free the form so the user can retry.
		mw.hook( 'embeddableContent.uploadmeta.failed' ).add( function () {
			uploadmetaFailed = true;
			cancelPendingUpload();
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
