/**
 * Multi-access editor for Special:AddSource / Special:UpdateSource.
 *
 * The HTMLForm renders a hidden `accessEntries` JSON field + this mount point.
 * The editor keeps a list of URL/file access entries; each entry has a
 * public/login status and (files) a licence. URL entries can fetch + archive
 * their text. Files are uploaded on demand through action=accessupload, so the
 * final form submission carries only the File: titles.
 */
( function () {
	'use strict';

	mw.loader.using( [ 'oojs-ui', 'mediawiki.api', 'mediawiki.util', 'mediawiki.notification' ] ).then( function () {
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

		function itemLabel() {
			var title = document.querySelector( '[name="wptitle"]' );
			var value = title ? String( title.value || '' ).trim() : '';
			return value || mw.config.get( 'wgTitle' ) || 'access-file';
		}

		function extensionOf( name ) {
			var m = /\.([A-Za-z0-9]{1,8})$/.exec( String( name || '' ) );
			return m ? m[ 1 ].toLowerCase() : '';
		}

		function labels( entries ) {
			var urlIndex = 0;
			return entries.map( function ( entry ) {
				if ( entry.type === 'url' ) {
					urlIndex += 1;
					return 'url' + urlIndex;
				}
				var ext = extensionOf( entry.fileTitle );
				return ext || 'file';
			} );
		}

		function renderCards( $editor, $hidden, entries ) {
			var $list = $editor.find( '.wb-access-list' );
			$list.empty();
			if ( entries.length === 0 ) {
				$list.append( $( '<p>' ).addClass( 'wb-access-empty' )
					.text( mw.msg( 'embeddablecontent-access-empty' ) ) );
				return;
			}
			var names = labels( entries );
			entries.forEach( function ( entry, index ) {
				var title = entry.type === 'url' ? entry.url : entry.fileTitle;
				var $card = $( '<div>' ).addClass( 'wb-access-card' );
				$card.append( $( '<span>' ).addClass( 'wb-access-card-label' )
					.text( names[ index ] + ': ' + title ) );
				$card.append( $( '<span>' ).addClass( 'wb-access-card-status' )
					.text( entry.public
						? mw.msg( 'embeddablecontent-access-public' )
						: mw.msg( 'embeddablecontent-access-login-only' ) ) );
				var $actions = $( '<span>' ).addClass( 'wb-access-card-actions' );
				var edit = new OO.ui.ButtonWidget( {
					label: mw.msg( 'embeddablecontent-access-edit' ),
					framed: false
				} );
				edit.on( 'click', function () {
					openDialog( $editor, $hidden, entries, index );
				} );
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
				$actions.append( edit.$element, remove.$element );
				$card.append( $actions );
				$list.append( $card );
			} );
		}

		function upload( mode, payload, file ) {
			var tokenPromise;
			try {
				tokenPromise = new mw.Api().get( { action: 'query', meta: 'tokens', type: 'csrf' } );
			} catch ( e ) {
				return Promise.reject( e );
			}
			return tokenPromise.then( function ( data ) {
				var token = data && data.query && data.query.tokens && data.query.tokens.csrftoken;
				if ( !token ) {
					throw new Error( 'no csrf token' );
				}
				var form = new FormData();
				form.append( 'action', 'accessupload' );
				form.append( 'format', 'json' );
				form.append( 'mode', mode );
				form.append( 'token', token );
				Object.keys( payload ).forEach( function ( key ) {
					form.append( key, payload[ key ] );
				} );
				if ( file ) {
					form.append( 'file', file );
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

		function openDialog( $editor, $hidden, entries, index ) {
			var editing = typeof index === 'number';
			var entry = editing ? entries[ index ] : { type: 'url', public: '1', archive: '1' };
			var name = 'embeddableContentAccessDialog' + ( ++sequence );
			function Dialog( cfg ) {
				Dialog.super.call( this, cfg );
			}
			OO.inheritClass( Dialog, OO.ui.ProcessDialog );
			Dialog.static.name = name;
			Dialog.static.title = mw.msg( editing
				? 'embeddablecontent-access-edit-title'
				: 'embeddablecontent-access-dialog-title' );
			Dialog.static.actions = [];
			Dialog.prototype.initialize = function () {
				Dialog.super.prototype.initialize.call( this );
				var $body = $( '<div>' ).addClass( 'wb-access-dialog' );
				var typeRadio = new OO.ui.RadioSelectInputWidget( {
					options: [
						{ data: 'url', label: mw.msg( 'embeddablecontent-access-type-url' ) },
						{ data: 'file', label: mw.msg( 'embeddablecontent-access-type-file' ) }
					]
				} );
				typeRadio.setValue( entry.type || 'url' );
				$body.append( new OO.ui.FieldLayout( typeRadio, {
					label: mw.msg( 'embeddablecontent-access-type' ),
					align: 'top'
				} ).$element );

				var urlInput = new OO.ui.TextInputWidget( { value: entry.url || '', placeholder: 'https://…' } );
				var archive = new OO.ui.CheckboxInputWidget( { selected: entry.archive === '1' } );
				var $urlSection = $( '<div>' ).addClass( 'wb-access-url-section' );
				$urlSection.append( new OO.ui.FieldLayout( urlInput, {
					label: mw.msg( 'embeddablecontent-access-url' ),
					align: 'top'
				} ).$element );
				$urlSection.append( new OO.ui.FieldLayout( archive, {
					label: mw.msg( 'embeddablecontent-access-archive' ),
					align: 'inline'
				} ).$element );
				$body.append( $urlSection );

				var fileMode = new OO.ui.RadioSelectInputWidget( {
					options: [
						{ data: 'upload', label: mw.msg( 'embeddablecontent-access-file-upload' ) },
						{ data: 'url', label: mw.msg( 'embeddablecontent-access-file-url' ) }
					],
					value: 'upload'
				} );
				var fileInput = $( '<input type="file">' );
				var fileUrl = new OO.ui.TextInputWidget( { placeholder: 'https://…' } );
				var existing = $( '<p>' ).addClass( 'wb-access-existing' );
				if ( entry.fileTitle ) {
					existing.text( mw.msg( 'embeddablecontent-access-current-file', entry.fileTitle ) );
				}
				var $fileSection = $( '<div>' ).addClass( 'wb-access-file-section' );
				$fileSection.append( new OO.ui.FieldLayout( fileMode, {
					label: mw.msg( 'embeddablecontent-access-file-mode' ),
					align: 'top'
				} ).$element );
				$fileSection.append( existing );
				var $fileUploadLayout = new OO.ui.FieldLayout(
					new OO.ui.Widget( { $element: $( '<div>' ).append( fileInput ) } ),
					{ label: mw.msg( 'embeddablecontent-access-file' ), align: 'top' }
				).$element;
				var $fileUrlLayout = new OO.ui.FieldLayout( fileUrl, {
					label: mw.msg( 'embeddablecontent-access-file-url' ),
					align: 'top'
				} ).$element;
				$fileSection.append( $fileUploadLayout, $fileUrlLayout );

				var internal = new OO.ui.CheckboxInputWidget( { selected: !entry.public } );
				var license = new OO.ui.DropdownInputWidget( {
					options: [ { data: '', label: mw.msg( 'embeddablecontent-access-license-none' ) } ].concat(
						Object.keys( licenseOptions ).map( function ( label ) {
							return { data: licenseOptions[ label ], label: label };
						} )
					),
					value: entry.license || ''
				} );
				var $licenseLayout = new OO.ui.FieldLayout( license, {
					label: mw.msg( 'embeddablecontent-access-license' ),
					align: 'top'
				} ).$element;
				$fileSection.append( new OO.ui.FieldLayout( internal, {
					label: mw.msg( 'embeddablecontent-access-internal' ),
					align: 'inline'
				} ).$element );
				$fileSection.append( $licenseLayout );
				$body.append( $fileSection );

				var status = $( '<p>' ).addClass( 'wb-access-status' );
				$body.append( status );
				var save = new OO.ui.ButtonWidget( {
					label: mw.msg( editing ? 'embeddablecontent-access-save' : 'embeddablecontent-access-add' ),
					flags: [ 'primary', 'progressive' ]
				} );
				var cancel = new OO.ui.ButtonWidget( { label: mw.msg( 'embeddablecontent-access-cancel' ) } );
				cancel.on( 'click', function () { dialog.close(); } );
				var $actions = $( '<div>' ).addClass( 'wb-access-dialog-actions' );
				$actions.append( save.$element, cancel.$element );
				$body.append( $actions );

				function sync() {
					var type = typeRadio.getValue();
					$urlSection.toggle( type === 'url' );
					$fileSection.toggle( type === 'file' );
					var fileModeValue = fileMode.getValue();
					$fileUploadLayout.toggle( type === 'file' && fileModeValue === 'upload' );
					$fileUrlLayout.toggle( type === 'file' && fileModeValue === 'url' );
					// Licence only applies to public files.
					$licenseLayout.toggle( type === 'file' && !internal.isSelected() );
				}
				typeRadio.on( 'change', sync );
				fileMode.on( 'change', sync );
				internal.on( 'change', sync );
				sync();

				save.on( 'click', function () {
					var type = typeRadio.getValue();
					var value = type === 'url' ? String( urlInput.getValue() || '' ).trim() : '';
					if ( type === 'url' && !/^https?:\/\//i.test( value ) ) {
						status.text( mw.msg( 'embeddablecontent-access-invalid-url' ) );
						return;
					}
					var fileModeValue = fileMode.getValue();
					var fileUrlValue = String( fileUrl.getValue() || '' ).trim();
					if ( type === 'file' && fileModeValue === 'url' && !/^https?:\/\//i.test( fileUrlValue ) ) {
						status.text( mw.msg( 'embeddablecontent-access-invalid-url' ) );
						return;
					}
					var currentFile = entry.fileTitle || '';
					if ( type === 'file' && fileModeValue === 'upload'
						&& currentFile === '' && !fileInput[ 0 ].files.length
					) {
						status.text( mw.msg( 'embeddablecontent-source-error-access-file-required' ) );
						return;
					}
					var publicAccess = !internal.isSelected();
					var base = {
						type: type,
						public: publicAccess ? '1' : ''
					};
					function finish( fileTitle ) {
						if ( type === 'url' ) {
							base.url = value;
							base.archive = archive.isSelected() ? '1' : '';
							base.archivedTitle = entry.archivedTitle || '';
						} else {
							base.fileTitle = fileTitle;
							base.license = publicAccess ? String( license.getValue() || '' ) : '';
						}
						if ( !base.public && type === 'file' ) {
							base.license = '';
						}
						if ( editing ) {
							entries[ index ] = base;
						} else {
							entries.push( base );
						}
						writeEntries( $hidden, entries );
						renderCards( $editor, $hidden, entries );
						dialog.close();
					}
					if ( type === 'url' ) {
						finish( null );
						return;
					}
					if ( currentFile !== '' && fileModeValue === 'upload' && !fileInput[ 0 ].files.length ) {
						finish( currentFile );
						return;
					}
					save.setDisabled( true );
					status.text( mw.msg( 'embeddablecontent-access-uploading' ) );
					var file = fileModeValue === 'upload' ? fileInput[ 0 ].files[ 0 ] : null;
					var payload = {
						label: itemLabel(),
						internal: publicAccess ? '0' : '1'
					};
					if ( fileModeValue === 'url' ) {
						payload.url = fileUrlValue;
					}
					// The API's `mode` is file|url, never the UI's
					// "upload" file-source value.
					var uploadMode = type === 'url' || fileModeValue === 'url' ? 'url' : 'file';
					upload( uploadMode, payload, file ).then( function ( data ) {
						finish( data.accessupload.fileTitle );
					} ).catch( function ( error ) {
						save.setDisabled( false );
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

		$( '.wb-access-editor' ).each( function () {
			var $editor = $( this );
			var $hidden = $( '#wpaccessEntries' );
			if ( !$hidden.length ) {
				return;
			}
			var entries = readEntries( $hidden );
			renderCards( $editor, $hidden, entries );
			var add = new OO.ui.ButtonWidget( {
				label: mw.msg( 'embeddablecontent-access-add' ),
				flags: [ 'progressive' ]
			} );
			add.on( 'click', function () {
				openDialog( $editor, $hidden, entries );
			} );
			$editor.find( '.wb-access-add-mount' ).append( add.$element );
		} );
	} );
}() );
