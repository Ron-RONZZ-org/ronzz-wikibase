/**
 * Parent-creation dialog.
 *
 * The "Create it now" link on a parent field opens an OOUI dialog with a
 * same-origin iframe running the REAL Special:AddSource/AddCollective flow
 * (`?wbparentdialog=1`). That flow behaves normally — website root metadata
 * auto-fetch, search-first classes, manual-only classes, the duplicate
 * guard — and ends on `parentdone/<Qid>`, which posts the Q-id back. The
 * opener appends it to the multi-value parent field.
 *
 * The same module runs inside the frame: it hides the site chrome and
 * intercepts `Item:Q…` links (the duplicate "use the existing item" path)
 * so they also return the Q-id instead of navigating away.
 */
( function () {
	'use strict';

	var isFrame = !!mw.config.get( 'wbParentDialogFrame' );
	var createdQid = mw.config.get( 'wbParentDialogCreatedQid' );

	function postToParent( qid, label ) {
		if ( !window.parent || window.parent === window ) {
			return;
		}
		window.parent.postMessage( {
			type: 'wb-parent-created',
			qid: qid,
			label: label || ''
		}, window.location.origin );
	}

	function extractQid( href ) {
		var url = String( href || '' );
		var match = url.match( /\/wiki\/(?:Item|Special:EntityPage|Special:EntityData):?(Q[1-9]\d*)/ )
			|| url.match( /\/entity\/(Q[1-9]\d*)/ )
			|| url.match( /[?&]title=(?:Item|Special:EntityPage):?(Q[1-9]\d*)/ );
		return match ? match[ 1 ] : null;
	}

	if ( isFrame ) {
		document.documentElement.classList.add( 'wb-parentdialog-frame' );
		if ( createdQid ) {
			postToParent( createdQid, '' );
		}
		document.addEventListener( 'click', function ( event ) {
			var link = event.target && event.target.closest
				? event.target.closest( 'a[href]' )
				: null;
			if ( !link ) {
				return;
			}
			var qid = extractQid( link.href );
			if ( !qid ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			postToParent( qid, link.textContent.trim() );
		}, true );
		return;
	}

	// ---------------------------------------------------------------- opener
	var windowManager = new OO.ui.WindowManager();
	$( document.body ).append( windowManager.$element );
	var active = null;

	/** Monotonic counter giving every open dialog a unique window name. */
	var dialogSequence = 0;

	/**
	 * Open an OOUI dialog whose body is built in initialize().
	 *
	 * A bare `new OO.ui.Dialog()` cannot be used with a WindowManager:
	 * `$body` is only created when the window is opened, and
	 * `OO.ui.Dialog.static.name` is empty (addWindows rejects it). The
	 * "Create it now" click therefore never opened a dialog. Use a named
	 * ProcessDialog subclass and build the body in initialize().
	 *
	 * @param {Object} config { title, size, classes }
	 * @param {Function} buildBody appends the content to the dialog's $body
	 * @param {Function} [onClose] runs after the dialog has fully closed
	 * @return {OO.ui.ProcessDialog}
	 */
	function openContentDialog( config, buildBody, onClose ) {
		var name = 'embeddableContentParentDialog' + ( ++dialogSequence );
		function ContentDialog( cfg ) {
			ContentDialog.super.call( this, cfg );
		}
		OO.inheritClass( ContentDialog, OO.ui.ProcessDialog );
		ContentDialog.static.name = name;
		ContentDialog.static.title = config.title;
		ContentDialog.static.actions = [];
		ContentDialog.prototype.initialize = function () {
			ContentDialog.super.prototype.initialize.call( this );
			buildBody( this );
		};
		var dialog = new ContentDialog( {
			size: config.size,
			classes: config.classes
		} );
		windowManager.addWindows( [ dialog ] );
		// OOUI Windows emit no 'close' event; the openWindow lifecycle's
		// `.closed` promise is the supported hook.
		windowManager.openWindow( dialog ).closed.then( function () {
			windowManager.removeWindows( [ name ] );
			if ( onClose ) {
				onClose();
			}
		} );
		return dialog;
	}

	function closeBeacon() {
		try {
			navigator.sendBeacon(
				mw.util.getUrl( 'Special:AddSource' ) + '?wbparentdialog=close'
			);
		} catch ( e ) {
			// The session marker also expires on its own; the beacon is
			// best-effort.
		}
	}

	function openDialog( link ) {
		var field = link.getAttribute( 'data-field' ) || 'parent';
		var label = link.getAttribute( 'data-parent-label' ) || '';
		var root = link.getAttribute( 'data-parent-url' ) || '';
		var url = new URL( link.getAttribute( 'href' ), window.location.origin );
		url.searchParams.set( 'wbparentdialog', '1' );
		if ( root ) {
			url.searchParams.set( 'url', root );
			url.searchParams.set( 'autofetch', '1' );
		}
		var iframe = document.createElement( 'iframe' );
		iframe.className = 'wb-parentdialog-iframe';
		iframe.src = url.toString();
		var dialog = openContentDialog(
			{
				title: mw.msg( 'embeddablecontent-parentdialog-title', label ),
				size: 'full',
				classes: [ 'wb-parentdialog' ]
			},
			function ( dlg ) {
				dlg.$body.append( iframe );
				var close = new OO.ui.ButtonWidget( {
					label: mw.msg( 'embeddablecontent-parentdialog-close' )
				} );
				close.on( 'click', function () {
					dlg.close();
				} );
				dlg.$body.append(
					$( '<div>' ).addClass( 'wb-author-dialog-actions' ).append( close.$element )
				);
			},
			function () {
				closeBeacon();
				active = null;
			}
		);
		active = { dialog: dialog, iframe: iframe, field: field };
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest
			? event.target.closest( 'a.wb-parent-create' )
			: null;
		if ( !link ) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		openDialog( link );
	} );

	window.addEventListener( 'message', function ( event ) {
		if ( event.origin !== window.location.origin ) {
			return;
		}
		if ( !active || event.source !== active.iframe.contentWindow ) {
			return;
		}
		var data = event.data || {};
		if ( data.type !== 'wb-parent-created' || !/^Q[1-9]\d*$/.test( data.qid || '' ) ) {
			return;
		}
		if ( mw.embeddableContent.entitysuggest && mw.embeddableContent.entitysuggest.addValue ) {
			mw.embeddableContent.entitysuggest.addValue( 'wp' + active.field, data.qid );
		}
		mw.notify(
			mw.msg( 'embeddablecontent-parentdialog-created', data.qid ),
			{ type: 'success' }
		);
		active.dialog.close();
		active = null;
	} );
}() );
