/**
 * Multi-image editor for AddPerson/AddCollective/AddSoftware (portrait /
 * logo / images). The hidden `imageEntries` field carries a JSON list of
 * File: titles; new uploads run through action=imageupload (the shared
 * ImageUploadHelper) and already carry their image item + licence.
 */
( function () {
	'use strict';

	mw.loader.using( [
		'oojs-ui',
		'mediawiki.api',
		'mediawiki.util',
		'ext.embeddableContent.entitysuggest'
	] ).then( function () {
		var sequence = 0;
		var windowManager = new OO.ui.WindowManager();
		$( document.body ).append( windowManager.$element );
		var licenseOptions = mw.config.get( 'wbAccessLicenseOptions' ) || {};
		var licenseClass = String( mw.config.get( 'wbAccessLicenseClass' ) || '' );
		var agentClasses = mw.config.get( 'wbAgentClasses' ) || [];

		function licenseOptionList() {
			return Object.keys( licenseOptions ).map( function ( label ) {
				return { data: String( licenseOptions[ label ] ), label: label };
			} );
		}

		function resolveLicense( value ) {
			var text = String( value || '' ).trim();
			if ( /^Q[1-9]\d*$/i.test( text ) ) {
				return text.toUpperCase();
			}
			return licenseOptions[ text ] !== undefined ? String( licenseOptions[ text ] ) : '';
		}

		function wireEntityCombo( combo, scope, multi ) {
			if ( !combo || !mw.embeddableContent || !mw.embeddableContent.entitysuggest
				|| typeof mw.embeddableContent.entitysuggest.wireWidget !== 'function'
			) {
				return;
			}
			combo.setData( { wbClasses: String( scope || '' ) } );
			mw.embeddableContent.entitysuggest.wireWidget( combo, !!multi );
		}

		function firstClipboardFile( clipboardData ) {
			if ( !clipboardData || !clipboardData.files ) {
				return null;
			}
			for ( var i = 0; i < clipboardData.files.length; i++ ) {
				if ( /^image\//.test( clipboardData.files[ i ].type ) ) {
					return clipboardData.files[ i ];
				}
			}
			return clipboardData.files.length ? clipboardData.files[ 0 ] : null;
		}

		function setInputFile( input, file ) {
			try {
				var dt = new DataTransfer();
				dt.items.add( file );
				input.files = dt.files;
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				return true;
			} catch ( e ) {
				return false;
			}
		}

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
						{ data: 'clipboard', label: mw.msg( 'embeddablecontent-upload-mode-clipboard' ) },
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
				var $clipboardTarget = $( '<div>' ).addClass( 'wb-clipboard-target' )
					.append( $( '<p>' ).addClass( 'wb-upload-drop-hint' )
						.text( mw.msg( 'embeddablecontent-upload-clipboard-hint' ) ) )
					.append( $( '<button type="button">' )
						.addClass( 'wb-upload-review-btn' )
						.text( mw.msg( 'embeddablecontent-upload-mode-clipboard' ) ) );
				var $clipboardLayout = new OO.ui.FieldLayout(
					new OO.ui.Widget( { $element: $clipboardTarget } ),
					{ label: mw.msg( 'embeddablecontent-upload-mode-clipboard' ), align: 'top' }
				).$element;
				var $urlLayout = new OO.ui.FieldLayout( urlInput, {
					label: mw.msg( 'embeddablecontent-access-file-url' ), align: 'top'
				} ).$element;
				var $existingLayout = new OO.ui.FieldLayout( existingInput, {
					label: mw.msg( 'embeddablecontent-media-existing' ), align: 'top'
				} ).$element;
				// Classic semantic license combobox (known list + entity search).
				var license = new OO.ui.ComboBoxInputWidget( { options: licenseOptionList() } );
				wireEntityCombo( license, licenseClass, false );
				var $licenseLayout = new OO.ui.FieldLayout( license, {
					label: mw.msg( 'embeddablecontent-access-license' ),
					help: mw.msg( 'embeddablecontent-access-license-help' ),
					align: 'top'
				} ).$element;
				var author = new OO.ui.TextInputWidget();
				var $authorLayout = new OO.ui.FieldLayout( author, {
					label: mw.msg( 'embeddablecontent-media-author' ), align: 'top'
				} ).$element;
				var authorEntity = new OO.ui.CheckboxInputWidget();
				var $authorEntityCheckLayout = new OO.ui.FieldLayout( authorEntity, {
					label: mw.msg( 'embeddablecontent-upload-author-entity-check' ), align: 'inline'
				} ).$element;
				var authorEntityCombo = new OO.ui.ComboBoxInputWidget( { options: [] } );
				wireEntityCombo( authorEntityCombo, agentClasses.join( '|' ), true );
				var $authorEntityLayout = new OO.ui.FieldLayout( authorEntityCombo, {
					label: mw.msg( 'embeddablecontent-upload-author-entity' ),
					help: mw.msg( 'embeddablecontent-upload-author-entity-help' ),
					align: 'top'
				} ).$element;
				var licenseInfo = new OO.ui.TextInputWidget();
				var $licenseInfoLayout = new OO.ui.FieldLayout( licenseInfo, {
					label: mw.msg( 'embeddablecontent-media-license-info' ), align: 'top'
				} ).$element;
				$body.append( new OO.ui.FieldLayout( mode, {
					label: mw.msg( 'embeddablecontent-media-source' ), align: 'top'
				} ).$element );
				$body.append(
					$fileLayout,
					$clipboardLayout,
					$urlLayout,
					$existingLayout,
					$licenseLayout,
					$authorLayout,
					$authorEntityCheckLayout,
					$authorEntityLayout,
					$licenseInfoLayout
				);

				function syncAuthorMode() {
					var entity = authorEntity.isSelected();
					$authorLayout.toggle( !entity );
					$authorEntityLayout.toggle( entity );
				}
				authorEntity.on( 'change', syncAuthorMode );
				syncAuthorMode();

				// Clipboard: paste anywhere in the dialog or drop onto the
				// target box; the explicit button reads navigator.clipboard.
				function readClipboard() {
					if ( !navigator.clipboard || !navigator.clipboard.read ) {
						status.text( mw.msg( 'embeddablecontent-upload-clipboard-error' ) );
						return;
					}
					navigator.clipboard.read().then( function ( items ) {
						for ( var i = 0; i < items.length; i++ ) {
							for ( var j = 0; j < items[ i ].types.length; j++ ) {
								if ( /^image\//.test( items[ i ].types[ j ] ) ) {
									return items[ i ].getType( items[ i ].types[ j ] );
								}
							}
						}
						throw new Error( 'no image' );
					} ).then( function ( blob ) {
						var ext = ( blob.type.split( '/' )[ 1 ] || 'png' ).replace( /[^a-z0-9]/g, '' );
						if ( !setInputFile( fileInput[ 0 ], new File( [ blob ], 'clipboard.' + ext, { type: blob.type } ) ) ) {
							throw new Error( 'cannot set' );
						}
					} ).catch( function () {
						status.text( mw.msg( 'embeddablecontent-upload-clipboard-error' ) );
					} );
				}
				$clipboardTarget.on( 'click', 'button', readClipboard );
				$body.on( 'paste', function ( e ) {
					if ( mode.getValue() !== 'clipboard' ) {
						return;
					}
					var file = firstClipboardFile( e.originalEvent && e.originalEvent.clipboardData );
					if ( file ) {
						e.preventDefault();
						setInputFile( fileInput[ 0 ], file );
					}
				} );
				$clipboardTarget.on( 'dragover', function ( e ) {
					e.preventDefault();
					$clipboardTarget.addClass( 'wb-upload-drop-active' );
				} ).on( 'dragleave', function () {
					$clipboardTarget.removeClass( 'wb-upload-drop-active' );
				} ).on( 'drop', function ( e ) {
					$clipboardTarget.removeClass( 'wb-upload-drop-active' );
					var file = firstClipboardFile( e.originalEvent && e.originalEvent.dataTransfer );
					if ( file ) {
						e.preventDefault();
						setInputFile( fileInput[ 0 ], file );
					}
				} );

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
					$fileLayout.toggle( value === 'file' || value === 'clipboard' );
					$clipboardLayout.toggle( value === 'clipboard' );
					$urlLayout.toggle( value === 'url' );
					$existingLayout.toggle( value === 'existing' );
					// Existing files already carry their facts on the image item.
					$licenseLayout.toggle( value !== 'existing' );
					$licenseInfoLayout.toggle( value !== 'existing' );
					$authorEntityCheckLayout.toggle( value !== 'existing' );
					if ( value === 'existing' ) {
						$authorLayout.hide();
						$authorEntityLayout.hide();
					} else {
						syncAuthorMode();
					}
				}
				mode.on( 'change', sync );
				sync();

				add.on( 'click', function () {
					var value = mode.getValue();
					var publicEntity = authorEntity.isSelected();
					var authorEntityValue = '';
					if ( publicEntity ) {
						authorEntityValue = String( authorEntityCombo.getValue() || '' ).trim();
						if ( !/^Q[1-9]\d*$/i.test( authorEntityValue ) ) {
							status.text( mw.msg( 'embeddablecontent-media-author-entity-required' ) );
							return;
						}
						authorEntityValue = authorEntityValue.toUpperCase();
					}
					var payload = {
						// The API's modes are file|url|existing; the clipboard
						// source is a browser file upload.
						mode: value === 'clipboard' ? 'file' : value,
						label: itemLabel(),
						description: inputValue( 'description' ),
						license: value === 'existing' ? '' : resolveLicense( license.getValue() ),
						licenseInfo: String( licenseInfo.getValue() || '' ),
						author: publicEntity ? '' : String( author.getValue() || '' ),
						authorIsEntity: publicEntity ? '1' : '',
						authorEntity: authorEntityValue
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
					var file = ( value === 'file' || value === 'clipboard' )
						? fileInput[ 0 ].files[ 0 ]
						: null;
					if ( ( value === 'file' || value === 'clipboard' ) && !file ) {
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
