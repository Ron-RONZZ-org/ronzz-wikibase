/**
 * Multi-image editor for AddPerson/AddCollective/AddSoftware (portrait /
 * logo / images). The hidden `imageEntries` field carries a JSON list of
 * File: titles; new uploads run through action=imageupload (the shared
 * ImageUploadHelper) and already carry their image item + licence.
 */
( function () {
	'use strict';

	mw.loader.using( [ 'oojs-ui', 'mediawiki.api', 'mediawiki.util' ] ).then( function () {
		var sequence = 0;
		var windowManager = new OO.ui.WindowManager();
		$( document.body ).append( windowManager.$element );
		var licenseOptions = mw.config.get( 'wbAccessLicenseOptions' ) || {};

		function apiUrl() {
			return ( mw.config.get( 'wgScriptPath' ) || '' ) + '/api.php';
		}
		function readEntries( $hidden ) {
			try {
				var value = JSON.parse( String( $hidden.val() || '[]' ) );
				return Array.isArray( value ) ? value : [];
			} catch ( e ) {
				return [];
			}
		}
		function writeEntries( $hidden, entries ) {
			$hidden.val( JSON.stringify( entries ) );
		}

		function inputValue( name ) {
			var el = document.querySelector( '[name="wp' + name + '"]' );
			return el ? String( el.value || '' ).trim() : '';
		}

		/** The item label used for the upload's File: name/page text. */
		function itemLabel() {
			var given = inputValue( 'givenName' );
			var family = inputValue( 'familyName' );
			if ( given || family ) {
				return ( given + ' ' + family ).trim();
			}
			return inputValue( 'label' ) || inputValue( 'title' ) || mw.config.get( 'wgTitle' ) || 'image';
		}
		function renderCards( $editor, $hidden, entries ) {
			var $list = $editor.find( '.wb-access-list' );
			$list.empty();
			if ( entries.length === 0 ) {
				$list.append( $( '<p>' ).addClass( 'wb-access-empty' )
					.text( mw.msg( 'embeddablecontent-media-empty' ) ) );
				return;
			}
			entries.forEach( function ( entry, index ) {
				var $card = $( '<div>' ).addClass( 'wb-access-card' );
				$card.append( $( '<span>' ).addClass( 'wb-access-card-label' )
					.text( entry.fileTitle ) );
				var remove = new OO.ui.ButtonWidget( {
					label: mw.msg( 'embeddablecontent-access-remove' ),
					framed: false,
					flags: [ 'destructive' ]
				} );
				remove.on( 'click', function () {
					entries.splice( index, 1 );
					writeEntries( $hidden, entries );
					renderCards( $editor, $hidden, entries );
				} );
				$card.append( $( '<span>' ).addClass( 'wb-access-card-actions' ).append( remove.$element ) );
				$list.append( $card );
			} );
		}

		function uploadImage( payload, file ) {
			return new mw.Api().get( { action: 'query', meta: 'tokens', type: 'csrf' } ).then( function ( data ) {
				var token = data && data.query && data.query.tokens && data.query.tokens.csrftoken;
				if ( !token ) {
					throw new Error( 'no csrf token' );
				}
				var form = new FormData();
				form.append( 'action', 'imageupload' );
				form.append( 'format', 'json' );
				form.append( 'token', token );
				Object.keys( payload ).forEach( function ( key ) {
					form.append( key, payload[ key ] );
				} );
				if ( file ) {
					form.append( 'wpmediaFile', file );
				}
				return fetch( apiUrl(), {
					method: 'POST',
					body: form,
					credentials: 'same-origin'
				} ).then( function ( response ) {
					return response.json().then( function ( json ) {
						if ( json.error ) {
							throw new Error( json.error.info || json.error.code );
						}
						return json;
					} );
				} );
			} );
		}

		function openDialog( $editor, $hidden, entries ) {
			var name = 'embeddableContentMediaDialog' + ( ++sequence );
			function Dialog( cfg ) {
				Dialog.super.call( this, cfg );
			}
			OO.inheritClass( Dialog, OO.ui.ProcessDialog );
			Dialog.static.name = name;
			Dialog.static.title = mw.msg( 'embeddablecontent-media-dialog-title' );
			Dialog.static.actions = [];
			Dialog.prototype.initialize = function () {
				Dialog.super.prototype.initialize.call( this );
				var $body = $( '<div>' ).addClass( 'wb-access-dialog' );
				var mode = new OO.ui.RadioSelectInputWidget( {
					options: [
						{ data: 'file', label: mw.msg( 'embeddablecontent-access-file-upload' ) },
						{ data: 'url', label: mw.msg( 'embeddablecontent-access-file-url' ) },
						{ data: 'existing', label: mw.msg( 'embeddablecontent-media-existing' ) }
					],
					value: 'file'
				} );
				var fileInput = $( '<input type="file" accept="image/*">' );
				var urlInput = new OO.ui.TextInputWidget( { placeholder: 'https://…' } );
				var existingInput = new OO.ui.TextInputWidget( { placeholder: 'File:Example.png' } );
				var $fileLayout = new OO.ui.FieldLayout(
					new OO.ui.Widget( { $element: $( '<div>' ).append( fileInput ) } ),
					{ label: mw.msg( 'embeddablecontent-access-file' ), align: 'top' }
				).$element;
				var $urlLayout = new OO.ui.FieldLayout( urlInput, {
					label: mw.msg( 'embeddablecontent-access-file-url' ), align: 'top'
				} ).$element;
				var $existingLayout = new OO.ui.FieldLayout( existingInput, {
					label: mw.msg( 'embeddablecontent-media-existing' ), align: 'top'
				} ).$element;
				var license = new OO.ui.DropdownInputWidget( {
					options: [ { data: '', label: mw.msg( 'embeddablecontent-access-license-none' ) } ].concat(
						Object.keys( licenseOptions ).map( function ( label ) {
							return { data: licenseOptions[ label ], label: label };
						} )
					)
				} );
				var $licenseLayout = new OO.ui.FieldLayout( license, {
					label: mw.msg( 'embeddablecontent-access-license' ), align: 'top'
				} ).$element;
				var author = new OO.ui.TextInputWidget();
				var $authorLayout = new OO.ui.FieldLayout( author, {
					label: mw.msg( 'embeddablecontent-media-author' ), align: 'top'
				} ).$element;
				var licenseInfo = new OO.ui.TextInputWidget();
				var $licenseInfoLayout = new OO.ui.FieldLayout( licenseInfo, {
					label: mw.msg( 'embeddablecontent-media-license-info' ), align: 'top'
				} ).$element;
				$body.append( new OO.ui.FieldLayout( mode, {
					label: mw.msg( 'embeddablecontent-media-source' ), align: 'top'
				} ).$element );
				$body.append( $fileLayout, $urlLayout, $existingLayout, $licenseLayout, $authorLayout, $licenseInfoLayout );

				var status = $( '<p>' ).addClass( 'wb-access-status' );
				$body.append( status );
				var add = new OO.ui.ButtonWidget( {
					label: mw.msg( 'embeddablecontent-media-add' ),
					flags: [ 'primary', 'progressive' ]
				} );
				var cancel = new OO.ui.ButtonWidget( { label: mw.msg( 'embeddablecontent-access-cancel' ) } );
				cancel.on( 'click', function () { dialog.close(); } );
				var $actions = $( '<div>' ).addClass( 'wb-access-dialog-actions' );
				$actions.append( add.$element, cancel.$element );
				$body.append( $actions );

				function sync() {
					var value = mode.getValue();
					$fileLayout.toggle( value === 'file' );
					$urlLayout.toggle( value === 'url' );
					$existingLayout.toggle( value === 'existing' );
					// Existing files already carry their facts on the image item.
					$licenseLayout.toggle( value !== 'existing' );
					$authorLayout.toggle( value !== 'existing' );
					$licenseInfoLayout.toggle( value !== 'existing' );
				}
				mode.on( 'change', sync );
				sync();

				add.on( 'click', function () {
					var value = mode.getValue();
					var payload = {
						mode: value,
						label: itemLabel(),
						description: inputValue( 'description' ),
						license: String( license.getValue() || '' ),
						licenseInfo: String( licenseInfo.getValue() || '' ),
						author: String( author.getValue() || '' )
					};
					if ( value === 'url' ) {
						payload.url = String( urlInput.getValue() || '' ).trim();
						if ( !/^https?:\/\//i.test( payload.url ) ) {
							status.text( mw.msg( 'embeddablecontent-access-invalid-url' ) );
							return;
						}
					}
					if ( value === 'existing' ) {
						payload.existing = String( existingInput.getValue() || '' ).trim();
					}
					if ( value !== 'existing' && !payload.license ) {
						status.text( mw.msg( 'embeddablecontent-image-license-required' ) );
						return;
					}
					var file = value === 'file' ? fileInput[ 0 ].files[ 0 ] : null;
					if ( value === 'file' && !file ) {
						status.text( mw.msg( 'embeddablecontent-media-no-file' ) );
						return;
					}
					add.setDisabled( true );
					status.text( mw.msg( 'embeddablecontent-access-uploading' ) );
					uploadImage( payload, file ).then( function ( data ) {
						entries.push( { fileTitle: data.imageupload.fileTitle } );
						writeEntries( $hidden, entries );
						renderCards( $editor, $hidden, entries );
						dialog.close();
					} ).catch( function ( error ) {
						add.setDisabled( false );
						status.text( mw.msg( 'embeddablecontent-access-upload-failed',
							error && error.message ? error.message : String( error ) ) );
					} );
				} );
				this.$body.append( $body );
			};
			var dialog = new Dialog( { size: 'medium' } );
			windowManager.addWindows( [ dialog ] );
			windowManager.openWindow( dialog ).closed.then( function () {
				windowManager.removeWindows( [ name ] );
			} );
		}

		$( '.wb-media-editor' ).each( function () {
			var $editor = $( this );
			var $hidden = $( '#wpimageEntries' );
			if ( !$hidden.length ) {
				return;
			}
			var entries = readEntries( $hidden );
			renderCards( $editor, $hidden, entries );
			var add = new OO.ui.ButtonWidget( {
				label: mw.msg( 'embeddablecontent-media-add' ),
				flags: [ 'progressive' ]
			} );
			add.on( 'click', function () {
				openDialog( $editor, $hidden, entries );
			} );
			$editor.find( '.wb-access-add-mount' ).append( add.$element );
		} );
	} );
}() );
