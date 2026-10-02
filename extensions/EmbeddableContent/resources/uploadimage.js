/**
 * Client-side image handling for the upload surfaces (Special:Upload and the
 * Add / Update portrait / logo sections):
 *
 *  1. LOCAL-FILE PREVIEW — selecting a file renders a thumbnail (or a
 *     file-type badge for non-images) with its pixel + byte size, the same
 *     preview the URL "Validate" path already shows. Previously only the
 *     URL mode previewed anything.
 *  2. RESIZE — when the section's "Resize large images before upload"
 *     checkbox is on (default), a raster image whose longest edge exceeds
 *     MAX_PX is downscaled on a canvas before submit (never upscaled; SVG
 *     and animated GIF are left untouched; EXIF orientation is preserved).
 *  3. AUTO-CORRECT EXTENSION — when the section's "Automatically correct
 *     the file extension" checkbox is on (default), the file's extension is
 *     set from its MIME type: the Add* file is renamed, and Special:Upload's
 *     `wpDestFile` is corrected (the server repeats the correction from the
 *     detected MIME — see UploadHooks::onUploadForm_BeforeProcessing).
 *
 * Discovery is by field NAME, no server config span: for every
 * `input[type=file][name=wp<X>File]` whose section carries the
 * `wp<X>Resize` / `wp<X>AutoExt` controls, the prefix `<X>` is derived from
 * the file field's name (`Upload` for Special:Upload's `wpUploadFile`).
 * Busy state disables the submit buttons, so a slow resize can never be
 * submitted mid-flight.
 */
( function () {
	'use strict';

	/** Longest-edge target for the resize (the requested 2000 px). */
	var MAX_PX = 2000;

	/** JPEG quality when re-encoding a resized image. */
	var JPEG_QUALITY = 0.9;

	/** MIME types the canvas resizer can safely re-encode (not SVG/GIF). */
	var RESIZABLE = { 'image/jpeg': true, 'image/png': true, 'image/webp': true };

	function extForMime( mime ) {
		if ( mw.embeddableContent && mw.embeddableContent.extensionForMime ) {
			return mw.embeddableContent.extensionForMime( mime );
		}
		return '';
	}

	function extensionOf( name ) {
		var m = /\.([A-Za-z0-9]{1,8})$/.exec( String( name || '' ) );
		return m ? m[ 1 ].toLowerCase() : '';
	}

	function replaceExtension( name, ext ) {
		if ( !ext ) {
			return name;
		}
		var dot = String( name ).lastIndexOf( '.' );
		var base = dot > 0 ? String( name ).slice( 0, dot ) : String( name );
		return base + '.' + ext;
	}

	function fileNameExt( mime ) {
		var m = String( mime || '' ).toLowerCase().split( ';' )[ 0 ].trim();
		return extForMime( m ) || ( m.split( '/' )[ 1 ] || 'file' ).replace( /[^a-z0-9]/g, '' );
	}

	/** Resolve a form field NAME to its real <input> (OOUI wrapper or php
	 * input; id → inner input → name fallback — the uploadmeta.js contract). */
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

	function isChecked( name ) {
		var el = findInput( name );
		return !!( el && el.checked );
	}

	/** The preview slot for a file input (created next to it if absent). */
	function previewFor( input ) {
		var host = input.closest
			? ( input.closest( '.oo-ui-fieldLayout' ) || input.closest( 'td.mw-input' ) || input.closest( 'tr' ) )
			: null;
		var scope = host || input.parentNode || document;
		var el = scope.querySelector ? scope.querySelector( '.wb-image-preview' ) : null;
		if ( !el ) {
			var form = input.closest ? input.closest( 'form' ) : null;
			el = ( form || document ).querySelector( '.wb-image-preview' );
		}
		if ( !el ) {
			el = document.createElement( 'div' );
			el.className = 'wb-image-preview wb-uploadmeta-preview';
			var anchor = host || input;
			if ( anchor.parentNode ) {
				anchor.parentNode.insertBefore( el, anchor.nextSibling );
			}
		}
		return el;
	}

	function renderPreview( el, info ) {
		if ( el._wbObjectUrl ) {
			URL.revokeObjectURL( el._wbObjectUrl );
			el._wbObjectUrl = null;
		}
		el.innerHTML = '';
		if ( info.image && info.url ) {
			el._wbObjectUrl = info.url;
			el.appendChild( $( '<img>' ).attr( 'src', info.url ).attr( 'alt', '' )[ 0 ] );
		} else if ( !info.image ) {
			el.appendChild( $( '<span class="wb-uploadmeta-fileicon"></span>' ).text( fileNameExt( info.mime ) )[ 0 ] );
		}
		var bits = [];
		if ( info.image && info.width && info.height ) {
			bits.push( info.width + ' \u00d7 ' + info.height + ' px' );
		}
		if ( info.fileSize ) {
			bits.push( formatBytes( info.fileSize ) );
		}
		if ( info.mime && !info.image ) {
			bits.push( info.mime );
		}
		if ( bits.length ) {
			el.appendChild( $( '<div class="wb-uploadmeta-size"></div>' ).text( bits.join( ' \u00b7 ' ) )[ 0 ] );
		}
		el.style.display = '';
	}

	function clearPreview( el ) {
		if ( el._wbObjectUrl ) {
			URL.revokeObjectURL( el._wbObjectUrl );
			el._wbObjectUrl = null;
		}
		el.innerHTML = '';
		el.style.display = 'none';
	}

	function formatBytes( bytes ) {
		if ( bytes < 1024 ) {
			return bytes + ' B';
		}
		if ( bytes < 1024 * 1024 ) {
			return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
		}
		return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
	}

	/** Load a file into a drawable ({drawable, width, height, close}). */
	function loadImage( file ) {
		if ( typeof createImageBitmap === 'function' ) {
			var make = function ( opts ) {
				return createImageBitmap( file, opts || {} ).then( function ( bmp ) {
					return {
						drawable: bmp,
						width: bmp.width,
						height: bmp.height,
						close: function () { if ( bmp.close ) { bmp.close(); } }
					};
				} );
			};
			// imageOrientation reads/honours EXIF rotation where supported;
			// older engines reject the option and fall through.
			return make( { imageOrientation: 'from-image' } ).catch( function () { return make(); } );
		}
		return new Promise( function ( resolve, reject ) {
			var img = new Image();
			var url = URL.createObjectURL( file );
			img.onload = function () {
				resolve( {
					drawable: img,
					width: img.naturalWidth,
					height: img.naturalHeight,
					close: function () { URL.revokeObjectURL( url ); }
				} );
			};
			img.onerror = function () {
				URL.revokeObjectURL( url );
				reject( new Error( 'image load failed' ) );
			};
			img.src = url;
		} );
	}

	function drawToBlob( drawable, width, height, type ) {
		var canvas = document.createElement( 'canvas' );
		canvas.width = width;
		canvas.height = height;
		var ctx = canvas.getContext && canvas.getContext( '2d' );
		if ( !ctx ) {
			return Promise.resolve( null );
		}
		try {
			ctx.drawImage( drawable, 0, 0, width, height );
		} catch ( e ) {
			return Promise.resolve( null );
		}
		return new Promise( function ( resolve ) {
			try {
				canvas.toBlob( function ( blob ) { resolve( blob ); }, type, JPEG_QUALITY );
			} catch ( e ) {
				resolve( null );
			}
		} );
	}

	function setInputFile( input, file ) {
		try {
			var dt = new DataTransfer();
			dt.items.add( file );
			input.files = dt.files;
		} catch ( e ) {
			// Leave the original file in place — preview still reflects it.
		}
	}

	/** Correct Special:Upload's wpDestFile extension from the file's MIME. */
	function syncDest( prefix, file ) {
		if ( prefix !== 'Upload' || !file ) {
			return;
		}
		var ext = extForMime( file.type ) || extensionOf( file.name );
		if ( !ext ) {
			return;
		}
		var dest = findInput( 'wpDestFile' );
		if ( !dest ) {
			return;
		}
		var current = String( dest.value || '' ).trim();
		dest.value = current ? replaceExtension( current, ext ) : file.name;
		dest.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		dest.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	function setBusy( input, busy ) {
		var form = input.closest ? input.closest( 'form' ) : null;
		if ( form ) {
			Array.prototype.forEach.call(
				form.querySelectorAll(
					'input[type="submit"], button[type="submit"], .oo-ui-buttonElement-button'
				),
				function ( b ) {
					b.disabled = busy;
					if ( busy ) {
						b.setAttribute( 'aria-disabled', 'true' );
					} else {
						b.removeAttribute( 'aria-disabled' );
					}
				}
			);
		}
		if ( busy ) {
			var preview = previewFor( input );
			preview.textContent = mw.msg( 'embeddablecontent-upload-resizing' );
			preview.style.display = '';
		}
	}

	/** Preview + resize + rename the selected file; calls done() when settled. */
	function processFile( input, prefix, done ) {
		var finished = false;
		var finish = function () {
			if ( !finished ) {
				finished = true;
				done();
			}
		};
		var file = input.files && input.files[ 0 ];
		var preview = previewFor( input );
		if ( !file ) {
			clearPreview( preview );
			finish();
			return;
		}

		var mime = String( file.type || '' );
		var isImage = /^image\//.test( mime ) ||
			/\.(jpe?g|png|gif|webp|svg|bmp|tiff?)$/i.test( file.name );
		var autoExt = isChecked( 'wp' + prefix + 'AutoExt' );
		var shouldResize = isChecked( 'wp' + prefix + 'Resize' );

		if ( !isImage || !window.File ) {
			renderPreview( preview, { image: false, mime: mime, fileSize: file.size } );
			syncDest( prefix, file );
			finish();
			return;
		}

		loadImage( file ).then( function ( im ) {
			var width = im.width;
			var height = im.height;
			var resizing = shouldResize && RESIZABLE[ mime ] && Math.max( width, height ) > MAX_PX;
			if ( !resizing ) {
				im.close();
				renderPreview( preview, {
					image: true,
					url: URL.createObjectURL( file ),
					width: width,
					height: height,
					fileSize: file.size,
					mime: mime
				} );
				renameInPlace( input, file, mime, autoExt );
				syncDest( prefix, input.files[ 0 ] || file );
				finish();
				return;
			}

			var scale = MAX_PX / Math.max( width, height );
			var outW = Math.round( width * scale );
			var outH = Math.round( height * scale );
			return drawToBlob( im.drawable, outW, outH, mime ).then( function ( blob ) {
				im.close();
				var out = file;
				if ( blob ) {
					// The encoder decides the actual type (a browser without
					// webp encoding falls back to png) — name the file after
					// the BLOB's type, never the requested one.
					var outType = blob.type || mime;
					var outExt = extForMime( outType ) || extForMime( mime ) || extensionOf( file.name );
					var name = autoExt ? replaceExtension( file.name, outExt ) : file.name;
					out = new File( [ blob ], name, { type: outType } );
					setInputFile( input, out );
				} else {
					renameInPlace( input, file, mime, autoExt );
					out = input.files[ 0 ] || file;
				}
				renderPreview( preview, {
					image: true,
					url: URL.createObjectURL( out ),
					width: outW,
					height: outH,
					fileSize: out.size,
					mime: out.type || mime
				} );
				syncDest( prefix, out );
			} );
		} ).then( finish, function () {
			// Decode failed (e.g. SVG) or the pipeline threw — preview the
			// bytes as-is and always release the busy state.
			renderPreview( preview, {
				image: true,
				url: URL.createObjectURL( file ),
				mime: mime,
				fileSize: file.size
			} );
			renameInPlace( input, file, mime, autoExt );
			syncDest( prefix, input.files[ 0 ] || file );
			finish();
		} );
	}

	/** Rename the file's extension to the MIME's (same bytes) when needed. */
	function renameInPlace( input, file, mime, autoExt ) {
		if ( !autoExt || !window.File ) {
			return;
		}
		var ext = extForMime( mime );
		if ( !ext || extensionOf( file.name ) === ext ) {
			return;
		}
		setInputFile( input, new File( [ file ], replaceExtension( file.name, ext ), { type: file.type } ) );
	}

	function wire( input ) {
		var m = /^wp(.+)File$/.exec( input.name || '' );
		if ( !m ) {
			return;
		}
		var prefix = m[ 1 ];
		// Only our surfaces carry the processing controls — never touch an
		// unrelated file input (e.g. MsUpload's).
		if ( !findInput( 'wp' + prefix + 'Resize' ) && !findInput( 'wp' + prefix + 'AutoExt' ) ) {
			return;
		}
		if ( input.dataset.wbImageWired === '1' ) {
			return;
		}
		input.dataset.wbImageWired = '1';

		input.addEventListener( 'change', function () {
			setBusy( input, true );
			processFile( input, prefix, function () { setBusy( input, false ); } );
		} );

		// Correct wpDestFile on submit-button click too (core's own dest-name
		// autofill may run after our change handler).
		var form = input.closest ? input.closest( 'form' ) : null;
		if ( form && prefix === 'Upload' ) {
			form.addEventListener( 'click', function ( e ) {
				var target = e.target;
				if ( !target || !target.closest ) {
					return;
				}
				if ( !target.closest( 'input[type="submit"], button[type="submit"]' ) ) {
					return;
				}
				syncDest( prefix, input.files && input.files[ 0 ] );
			}, true );
		}
	}

	function wireAll() {
		Array.prototype.forEach.call(
			document.querySelectorAll( 'input[type="file"][name]' ),
			wire
		);
	}

	mw.loader.using( 'oojs-ui' ).then( function () {
		wireAll();
		// The Add* file input is hidden/re-inserted by the OOUI mode radio
		// (and the whole section by the include toggle) — re-wire anything
		// that appears later (idempotent via the dataset marker).
		if ( typeof MutationObserver !== 'undefined' ) {
			new MutationObserver( function () { wireAll(); } ).observe( document.body, { childList: true, subtree: true } );
		}
	} );
}() );
