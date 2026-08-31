/**
 * Sitelink tab popup (issue #7 follow-up): the red "Sitelink" tab on content
 * pages opens a dialog that links the current page to an item — type a label
 * to search (action=entitysearch, the extension's FULLTEXT search — the same
 * wiring as entitysuggest.js) or enter a Q-id directly — then wbsetsitelink
 * on confirm. The blue tab is a plain link to the Item page (no JS needed).
 *
 * Anonymous users get the no-JS fallback (Special:NewItem prefill): the
 * dialog only opens when a session user is present.
 *
 * @license GPL-2.0-or-later
 */
( function () {
	'use strict';

	function openSitelinkDialog( $link ) {
		var api = new mw.Api();

		var combo = new OO.ui.ComboBoxInputWidget( {
			placeholder: mw.msg( 'embeddablecontent-sitelink-placeholder' ),
			options: []
		} );

		// Label search with the same action=entitysearch wiring as
		// entitysuggest.js; a typed Q-id skips the search entirely.
		combo.on( 'change', OO.ui.debounce( function ( value ) {
			var q = String( value || '' ).trim();
			if ( q.length < 2 || /^[QP]\d+$/i.test( q ) ) {
				if ( q === '' || /^[QP]\d+$/i.test( q ) ) {
					combo.setOptions( [] );
					combo.getMenu().toggle( false );
				}
				return;
			}
			api.get( {
				action: 'entitysearch',
				search: q,
				language: mw.config.get( 'wgUserLanguage' ) || 'en',
				limit: 10,
				format: 'json'
			} ).then( function ( data ) {
				var options = ( data.search || [] ).map( function ( row ) {
					var label = row.id;
					if ( row.label ) {
						label = row.id + ' — ' + row.label;
					}
					if ( row.description ) {
						label += ' (' + row.description + ')';
					}
					return { data: row.id, label: label };
				} );
				combo.setOptions( options );
				combo.getMenu().toggle( true );
			} ).catch( function () {
				// Non-fatal: a typed Q-id still works.
			} );
		}, 250 ) );

		var field = new OO.ui.FieldLayout( combo, {
			label: mw.msg( 'embeddablecontent-sitelink-dialog-title' ),
			align: 'top'
		} );

		function SitelinkDialog() {
			SitelinkDialog.super.call( this, { size: 'medium' } );
		}
		OO.inheritClass( SitelinkDialog, OO.ui.ProcessDialog );
		SitelinkDialog.static.name = 'embeddableContentSitelink';
		SitelinkDialog.static.title = mw.msg( 'embeddablecontent-sitelink-dialog-title' );
		SitelinkDialog.static.actions = [
			{ action: 'link', label: mw.msg( 'embeddablecontent-sitelink-link' ), flags: [ 'primary', 'progressive' ] },
			{ action: 'cancel', label: mw.msg( 'embeddablecontent-sitelink-cancel' ), flags: 'safe' }
		];
		SitelinkDialog.prototype.getBodyHeight = function () {
			return 110;
		};
		SitelinkDialog.prototype.initialize = function () {
			SitelinkDialog.super.prototype.initialize.call( this );
			this.$body.append( field.$element );
			setTimeout( function () {
				combo.focus();
			} );
		};
		SitelinkDialog.prototype.getActionProcess = function ( action ) {
			if ( action === 'link' ) {
				var id = String( combo.getValue() || '' ).trim();
				if ( !/^Q[1-9]\d*$/i.test( id ) ) {
					return new OO.ui.Process().reject( mw.msg( 'embeddablecontent-sitelink-badid' ) );
				}
				return new OO.ui.Process( function () {
					return api.postWithToken( 'csrf', {
						action: 'wbsetsitelink',
						id: id.toUpperCase(),
						linksite: 'wikibase',
						linktitle: mw.config.get( 'wgPageName' ),
						format: 'json'
					} ).then( function () {
						window.location.reload();
					}, function ( code, data ) {
						var info = data && data.error && data.error.info
							? data.error.info
							: String( code );
						return new OO.ui.Error( mw.msg( 'embeddablecontent-sitelink-error', info ) );
					} );
				} );
			}
			return SitelinkDialog.super.prototype.getActionProcess.call( this, action );
		};

		var windowManager = new OO.ui.WindowManager();
		$( document.body ).append( windowManager.$element );
		windowManager.addWindows( [ new SitelinkDialog() ] );
		windowManager.openWindow( 'embeddableContentSitelink' ).closed.then( function () {
			windowManager.destroy();
		} );
	}

	mw.loader.using( 'oojs-ui' ).then( function () {
		// Only the red tab opens the popup; the blue tab is a plain link.
		$( document ).on( 'click', 'li.ca-sitelink.needs-set > a', function ( e ) {
			if ( mw.config.get( 'wgUserName' ) === null ) {
				// Anonymous: let the default action run (Special:NewItem
				// prefilled) — wbsetsitelink needs a session user anyway.
				return;
			}
			e.preventDefault();
			openSitelinkDialog( $( this ) );
		} );
	} );
}() );
