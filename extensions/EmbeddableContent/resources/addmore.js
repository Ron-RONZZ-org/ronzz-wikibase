/**
 * "Add more" success popup on the content-creation pages
 * (Special:AddQuotation / AddMath / AddCodeSnippet). When the contributor
 * uses the "Add more" button, the submit redirects back to the reopened form
 * with ?addmore=1&created=<Qid> (SpecialAddContentItem::addMoreUrl); the
 * server sets wbJustAddedItem (and wbJustAddedEditUrl) and loads this module.
 *
 * It shows a dialog with the just-added item's preview (the exact in-wiki
 * preview variant — resources/contentpreview.js) and the entity actions
 * below it (Edit content / Copy Embed code / Copy citation — the shared
 * resources/entityactions.js), matching the entity page toolbar. Closing the
 * dialog leaves the reopened form ready for the next item.
 */
( function () {
	'use strict';

	var ID_PATTERN = /^Q[1-9]\d*$/;
	var item = mw.config.get( 'wbJustAddedItem' );
	var editUrl = mw.config.get( 'wbJustAddedEditUrl' ) || '';
	if ( typeof item !== 'string' || !ID_PATTERN.test( item ) ) {
		return;
	}

	mw.loader.using( [
		'oojs-ui',
		'ext.embeddableContent.preview',
		'ext.embeddableContent.entityactions'
	] ).then( function () {
		var $preview = $( '<div class="wb-addmore-preview"></div>' );
		var $actions = $( '<div class="wb-embed-toolbar wb-addmore-actions"></div>' );
		if ( editUrl ) {
			$actions.append(
				$( '<a class="wb-embed-toolbar-btn wb-update-basic-btn"></a>' )
					.attr( 'href', editUrl )
					.text( mw.msg( 'embeddablecontent-update-content-button' ) )
			);
		}
		mw.embeddableContent.entityActions.attach( $actions, item );

		function AddMoreDialog( config ) {
			AddMoreDialog.super.call( this, config );
		}
		OO.inheritClass( AddMoreDialog, OO.ui.ProcessDialog );
		AddMoreDialog.static.name = 'embeddableContentAddMore';
		AddMoreDialog.static.title = mw.msg( 'embeddablecontent-addmore-title' );
		AddMoreDialog.static.actions = [
			{ action: 'close', label: mw.msg( 'embeddablecontent-addmore-close' ), flags: 'safe' }
		];
		AddMoreDialog.prototype.getBodyHeight = function () {
			return 400;
		};
		AddMoreDialog.prototype.initialize = function () {
			AddMoreDialog.super.prototype.initialize.call( this );
			this.$body.append(
				$( '<div class="wb-addmore"></div>' ).append( $preview ).append( $actions )
			);
			mw.embeddableContent.preview.render( $preview, item );
		};
		AddMoreDialog.prototype.getActionProcess = function ( action ) {
			if ( action === 'close' ) {
				return new OO.ui.Process( function () {
					this.close();
				}, this );
			}
			return AddMoreDialog.super.prototype.getActionProcess.call( this, action );
		};

		var windowManager = new OO.ui.WindowManager();
		$( document.body ).append( windowManager.$element );
		windowManager.addWindows( [ new AddMoreDialog() ] );
		windowManager.openWindow( 'embeddableContentAddMore' ).closed.then( function () {
			windowManager.destroy();
		} );
	} );
}() );
