/**
 * Image editor for the upload surfaces (Special:Upload, Add / Update portrait/
 * logo fields, the Add* multi-image dialog).
 *
 * The heavy TOAST UI bundle is loaded ON DEMAND (mw.loader.using) only when
 * the user clicks "Edit image", so a normal upload pays nothing. Raster
 * formats only (JPEG/PNG/WebP); SVG and animated GIF are deliberately left
 * untouched (the editor would rasterise them and destroy vector/animation).
 *
 * The editor returns a new File (same name, MIME derived from the chosen
 * output format); the upload pipeline replaces the file input's file and
 * re-runs its preview/resize/auto-extension flow.
 */
( function () {
	'use strict';

	var OUTPUT_FORMATS = {
		'image/jpeg': 'jpeg',
		'image/png': 'png',
		// Canvas WebP encoding is not universal; PNG is a safe output and the
		// auto-extension step renames the destination accordingly.
		'image/webp': 'png'
	};

	function canEdit( file ) {
		return !!file && Object.prototype.hasOwnProperty.call( OUTPUT_FORMATS, file.type );
	}

	function dataUrlToFile( dataUrl, name, mime ) {
		var comma = dataUrl.indexOf( ',' );
		var binary = atob( dataUrl.slice( comma + 1 ) );
		var bytes = new Uint8Array( binary.length );
		for ( var i = 0; i < binary.length; i++ ) {
			bytes[ i ] = binary.charCodeAt( i );
		}
		return new File( [ bytes ], name, { type: mime } );
	}

	function open( file ) {
		if ( !canEdit( file ) ) {
			return Promise.resolve( null );
		}
		return mw.loader.using( [ 'oojs-ui' ] ).then( function () {
			return new Promise( function ( resolve, reject ) {
				var objectUrl = URL.createObjectURL( file );
				var windowManager = new OO.ui.WindowManager();
				$( document.body ).append( windowManager.$element );
				var result = null;

				function Dialog( config ) {
					Dialog.super.call( this, config );
				}
				OO.inheritClass( Dialog, OO.ui.ProcessDialog );
				Dialog.static.name = 'wbImageEditor';
				Dialog.static.title = mw.msg( 'embeddablecontent-imageeditor-title' );
				Dialog.static.actions = [
					{
						action: 'apply',
						label: mw.msg( 'embeddablecontent-imageeditor-apply' ),
						flags: [ 'primary', 'progressive' ]
					},
					{
						action: 'cancel',
						label: mw.msg( 'embeddablecontent-imageeditor-cancel' ),
						flags: [ 'safe' ]
					}
				];
				Dialog.prototype.initialize = function () {
					Dialog.super.prototype.initialize.call( this );
					this.$body.append(
						$( '<div>' ).addClass( 'wb-imageeditor-container' )
					);
				};
				Dialog.prototype.getBodyHeight = function () {
					return Math.max( 420, Math.min( Math.round( window.innerHeight * 0.72 ), 820 ) );
				};
				Dialog.prototype.getSetupProcess = function ( data ) {
					return Dialog.super.prototype.getSetupProcess.call( this, data ).next( function () {
						var container = this.$body.find( '.wb-imageeditor-container' )[ 0 ];
						try {
							this.editor = new tui.ImageEditor( container, {
								includeUI: {
									loadImage: { path: objectUrl, name: file.name },
									menu: [ 'crop', 'flip', 'rotate', 'filter' ],
									initMenu: 'filter',
									uiSize: { width: '100%', height: '100%' },
									menuBarPosition: 'bottom'
								},
								cssMaxWidth: 1600,
								cssMaxHeight: 1200,
								usageStatistics: false
							} );
						} catch ( e ) {
							reject( e );
							this.close( { action: 'cancel' } );
						}
					}, this );
				};
				Dialog.prototype.getActionProcess = function ( action ) {
					var dialog = this;
					if ( action === 'apply' ) {
						return new OO.ui.Process( function () {
							var format = OUTPUT_FORMATS[ file.type ] || 'png';
							var dataUrl = dialog.editor.toDataURL( { format: format, quality: 0.92 } );
							result = dataUrlToFile( dataUrl, file.name, 'image/' + format );
							dialog.close( { action: action } );
						} );
					}
					if ( action === 'cancel' ) {
						return new OO.ui.Process( function () {
							dialog.close( { action: action } );
						} );
					}
					return Dialog.super.prototype.getActionProcess.call( this, action );
				};
				Dialog.prototype.getTeardownProcess = function ( data ) {
					return Dialog.super.prototype.getTeardownProcess.call( this, data ).next( function () {
						if ( this.editor ) {
							try {
								this.editor.destroy();
							} catch ( e ) {
								// Already torn down by a failed setup.
							}
							this.editor = null;
						}
					}, this );
				};

				var dialog = new Dialog( { size: 'large' } );
				windowManager.addWindows( [ dialog ] );
				windowManager.openWindow( dialog ).closed.then( function () {
					windowManager.removeWindows( [ dialog.static.name ] );
					URL.revokeObjectURL( objectUrl );
					resolve( result );
				} );
			} );
		} );
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.imageEditor = { open: open, canEdit: canEdit };
}() );
