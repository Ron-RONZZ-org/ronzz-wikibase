/**
 * Upload metadata "Validate" step for URL-mode image uploads
 * (Special:Upload URL mode + the Add* portrait/logo URL fields).
 *
 * Two fetch paths, chosen by the source host:
 *  - Wikimedia hosts (wikipedia.org / wikimedia.org): the BROWSER queries
 *    the Commons API directly (`origin=*`, CORS-open). The request leaves
 *    from the user's residential IP, so the instance's shared Oracle-Cloud
 *    IP never draws the Wikimedia 429 rate-limit blocks (fceb99d).
 *  - everything else: the server-side api.php?action=uploadmeta (SSRF-
 *    guarded) — most sites do not send CORS headers, so only the server
 *    can read them.
 *
 * The validate result best-effort auto-fills the form's name / description /
 * author / license fields and renders a preview thumbnail + pixel + byte
 * size.
 *
 * 429 fallback for the image BYTES: the server-side UploadFromUrl download
 * of a Wikimedia URL is replaced by the browser itself — on submit the JS
 * fetches the image as a blob and re-posts it as a plain file upload
 * (mode switched to "file from device"). Residential IP again, no server
 * request to Wikimedia at all. MAX_BLOB_BYTES guards the in-tab buffer and
 * must match $wgMaxUploadSize['url'] on the server (deploy config).
 *
 * Wiring: each server-rendered <span class="wb-uploadmeta"
 * data-config='{"urlField":"wpportraitUrl","fileField":"wpportraitFile",
 * "modeField":"wpportraitMode","fileMode":"file",
 * "targets":{"name":…,"description":…,"author":…,"license":…,"licenseInfo":…}}'>
 * describes one URL field and where to write the fetched values. The server
 * injects the span next to the field (ImageUploadHelper for the Add* pages,
 * UploadHooks for Special:Upload).
 */
( function () {
	'use strict';

	/** Must match $wgMaxUploadSize['url'] (deploy config) — see README/runbook. */
	var MAX_BLOB_BYTES = 100 * 1024 * 1024;

	/** Wikimedia API hosts whose JSON + image bytes are browser-readable. */
	function isWikimediaHost( host ) {
		host = String( host || '' ).toLowerCase();
		return host === 'wikipedia.org' || host === 'wikimedia.org' ||
			host.endsWith( '.wikipedia.org' ) || host.endsWith( '.wikimedia.org' );
	}

	/** Mirrors the PHP WikimediaFileUrl::fileTitle() extraction. */
	function extractFileTitle( url ) {
		var u;
		try {
			u = new URL( url );
		} catch ( e ) {
			return null;
		}
		if ( !isWikimediaHost( u.hostname ) ) {
			return null;
		}
		var path = u.pathname || '';
		var wiki = path.match( /^\/wiki\/(?:Special:FilePath\/)?(File:[^/]+)$/i );
		if ( wiki ) {
			return wiki[ 1 ].replace( /_/g, ' ' ).replace( /\s+/g, ' ' ).trim();
		}
		var segs = path.split( '/' ).filter( function ( s ) { return s !== ''; } );
		if ( !segs.length ) {
			return null;
		}
		var name;
		if ( path.indexOf( '/thumb/' ) !== -1 && segs.length >= 2 ) {
			name = segs[ segs.length - 2 ];
		} else {
			name = segs[ segs.length - 1 ];
		}
		if ( !name ) {
			return null;
		}
		return ( 'File:' + name ).replace( /_/g, ' ' ).replace( /\s+/g, ' ' ).trim();
	}

	/** Commons extmetadata values are HTML fragments — strip + collapse. */
	function cleanText( value ) {
		if ( !value ) {
			return '';
		}
		var text = String( value );
		if ( typeof document !== 'undefined' ) {
			var div = document.createElement( 'div' );
			div.innerHTML = text;
			text = div.textContent || div.innerText || '';
		}
		return text.replace( /\s+/g, ' ' ).trim().slice( 0, 250 );
	}

	function fieldVal( cfg, key ) {
		var id = cfg.targets && cfg.targets[ key ];
		if ( !id ) {
			return null;
		}
		var el = document.getElementById( id );
		if ( !el ) {
			return null;
		}
		var set = function ( value ) {
			el.value = String( value || '' );
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		};
		var get = function () { return el.value; };
		return { el: el, set: set, get: get };
	}

	/** Resolve a license LABEL ("CC BY-SA 4.0") to an item id via the
	 * instance's wbsearchentities (the combobox submits item ids). */
	function resolveLicense( label, onReady ) {
		var api = new mw.Api();
		var queries = [ label ];
		var tc = label.replace( /(^|\s)(\S)/g, function ( m, pre, ch ) { return pre + ch.toUpperCase(); } );
		if ( tc !== label ) {
			queries.push( tc );
		}
		var pending = queries.map( function ( q ) {
			return api.get( {
				action: 'wbsearchentities',
				search: q,
				language: mw.config.get( 'wgUserLanguage' ) || 'en',
				type: 'item',
				limit: 5,
				format: 'json'
			} );
		} );
		Promise.all( pending ).then( function ( results ) {
			var seen = {};
			( results || [] ).forEach( function ( data ) {
				( data.search || [] ).forEach( function ( row ) {
					if ( seen[ row.id ] || seen[ row.label ] ) {
						return;
					}
					seen[ row.id ] = seen[ row.label ] = true;
					if ( String( row.label || '' ).toLowerCase() === label.toLowerCase() ) {
						onReady( row.id );
					}
				} );
			} );
		} ).catch( function () {} );
	}

	function formatBytes( bytes ) {
		if ( bytes === null || bytes === undefined ) {
			return '';
		}
		if ( bytes < 1024 ) {
			return bytes + ' B';
		}
		if ( bytes < 1024 * 1024 ) {
			return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
		}
		return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
	}

	function applyMeta( cfg, meta, $preview ) {
		var name = fieldVal( cfg, 'name' );
		if ( name && meta.name ) {
			name.set( meta.name );
		}
		var description = fieldVal( cfg, 'description' );
		if ( description && meta.description ) {
			description.set( meta.description );
		}
		var author = fieldVal( cfg, 'author' );
		if ( author && meta.author ) {
			author.set( meta.author );
		}
		var licenseInfo = fieldVal( cfg, 'licenseInfo' );
		if ( licenseInfo && meta.credit && !licenseInfo.get() ) {
			licenseInfo.set( meta.credit );
		}
		var license = fieldVal( cfg, 'license' );
		if ( license && meta.license ) {
			resolveLicense( meta.license, function ( qid ) {
				license.set( qid );
			} );
		}

		// Preview thumbnail + pixel size + byte size.
		var html = '';
		if ( meta.thumbUrl || meta.sourceUrl ) {
			html += '<img src="' + mw.html.escape( meta.thumbUrl || meta.sourceUrl ) + '" alt="" loading="lazy">';
		}
		var bits = [];
		if ( meta.width && meta.height ) {
			bits.push( meta.width + ' × ' + meta.height + ' px' );
		}
		if ( meta.fileSize ) {
			bits.push( formatBytes( meta.fileSize ) );
		}
		if ( bits.length ) {
			html += '<div class="wb-uploadmeta-size">' + mw.html.escape( bits.join( ' · ' ) ) + '</div>';
		}
		$preview.html( html ).show();

		( meta.warnings || [] ).forEach( function ( w ) {
			mw.notify( w, { type: 'warn', title: mw.msg( 'embeddablecontent-uploadmeta-warning' ) } );
		} );
	}

	function showError( $status, err ) {
		mw.notify( mw.msg( 'embeddablecontent-uploadmeta-failed' ), { type: 'error' } );
		if ( err && err.message ) {
			$status.text( String( err.message ).slice( 0, 300 ) ).show();
		}
	}

	function validate( cfg, $url, $preview, $status, $btn ) {
		var url = String( $url.val() || '' ).trim();
		if ( !url ) {
			return;
		}
		$btn.prop( 'disabled', true );
		$status.text( mw.msg( 'embeddablecontent-uploadmeta-validating' ) ).show();

		var finish = function () { $btn.prop( 'disabled', false ); };

		var fromServer = function () {
			var api = new mw.Api();
			return api.get( { action: 'uploadmeta', url: url, format: 'json' } )
				.then( function ( data ) { return data.uploadmeta; } );
		};

		var promise;
		var title = extractFileTitle( url );
		if ( title ) {
			// Wikimedia: the browser queries the Commons API directly
			// (origin=* — CORS-open, residential IP). Server fallback if
			// the browser path fails.
			var apiUrl = 'https://commons.wikimedia.org/w/api.php?origin=*&action=query' +
				'&prop=imageinfo&iiprop=extmetadata%7Csize%7Curl&format=json&formatversion=2&titles=' +
				encodeURIComponent( title );
			promise = fetch( apiUrl ).then( function ( r ) {
				if ( !r.ok ) {
					throw new Error( 'HTTP ' + r.status );
				}
				return r.json();
			} ).then( function ( data ) {
				var info = ( data.query && data.query.pages && data.query.pages[ 0 ] &&
					data.query.pages[ 0 ].imageinfo ) ? data.query.pages[ 0 ].imageinfo[ 0 ] : null;
				if ( !info ) {
					throw new Error( 'no imageinfo for ' + title );
				}
				var ext = info.extmetadata || {};
				return {
					name: cleanText( ext.ObjectName && ext.ObjectName.value ) ||
						cleanText( ext.ImageDescription && ext.ImageDescription.value ),
					description: cleanText( ext.ImageDescription && ext.ImageDescription.value ),
					author: cleanText( ext.Artist && ext.Artist.value ),
					license: cleanText( ext.LicenseShortName && ext.LicenseShortName.value ),
					credit: cleanText( ext.Credit && ext.Credit.value ),
					width: info.width || null,
					height: info.height || null,
					fileSize: info.size || null,
					mime: info.mime || null,
					thumbUrl: info.thumburl || null,
					sourceUrl: url,
					warnings: []
				};
			} ).catch( fromServer );
		} else {
			promise = fromServer();
		}

		promise.then( function ( meta ) {
			lastMeta[ cfg.urlField ] = meta;
			applyMeta( cfg, meta, $preview );
			finish();
		} ).catch( function ( err ) {
			showError( $status, err );
			finish();
		} );
	}

	/** Last metadata per URL field — the submit-time blob guard uses the
	 * byte size to refuse oversized files BEFORE downloading them. */
	var lastMeta = {};

	mw.loader.using( 'oojs-ui' ).then( function () {
		$( '.wb-uploadmeta' ).each( function () {
			var $wrapper = $( this );
			var cfg = $wrapper.data( 'config' ) || {};
			if ( !cfg.urlField ) {
				return;
			}
			var $url = $( '#' + cfg.urlField );
			if ( !$url.length ) {
				return;
			}

			var $status = $( '<div class="wb-uploadmeta-status"></div>' ).hide();
			var $preview = $( '<div class="wb-uploadmeta-preview"></div>' ).hide();
			var $btn = $( '<button type="button" class="wb-uploadmeta-validate">' )
				.text( mw.msg( 'embeddablecontent-uploadmeta-validate' ) )
				.on( 'click', function () {
					validate( cfg, $url, $preview, $status, $btn );
				} );

			$wrapper.append( $btn ).append( $status ).append( $preview );

			var $form = $url.closest( 'form' );
			$form.on( 'submit', function ( e ) {
				var url = String( $url.val() || '' ).trim();
				if ( !url || !isWikimediaHost( url ) || !cfg.fileField || !cfg.modeField ) {
					return true;
				}
				// The URL-mode radio value differs across surfaces: the Add*
				// pages use lowercase 'url', Special:Upload uses core's
				// 'Url'. Normalise so the blob fallback fires on both.
				var modeVal = String(
					$form.find( 'input[name="' + cfg.modeField + '"]:checked' ).val() || ''
				).toLowerCase();
				if ( modeVal !== 'url' ) {
					return true;
				}
				var known = lastMeta[ cfg.urlField ] || {};
				if ( known.fileSize && known.fileSize > MAX_BLOB_BYTES ) {
					$status.text( mw.msg( 'embeddablecontent-uploadmeta-bytesize' ) ).show();
					e.preventDefault();
					return false;
				}
				// Convert this Wikimedia URL upload to a browser-supplied
				// file: fetch the bytes (residential IP), fill the file
				// input, switch the mode radio, resubmit.
				e.preventDefault();
				$status.text( mw.msg( 'embeddablecontent-uploadmeta-validating' ) ).show();
				fetch( url ).then( function ( r ) {
					if ( !r.ok ) {
						throw new Error( 'HTTP ' + r.status );
					}
					return r.blob();
				} ).then( function ( blob ) {
					if ( blob.size > MAX_BLOB_BYTES ) {
						throw new Error( mw.msg( 'embeddablecontent-uploadmeta-bytesize' ) );
					}
					var name = url.split( '/' ).pop().split( '?' )[ 0 ] || 'image';
					var file = new File( [ blob ], name, { type: blob.type || 'application/octet-stream' } );
					var dt = new DataTransfer();
					dt.items.add( file );
					var $file = $( '#' + cfg.fileField );
					$file.prop( 'disabled', false );
					$file[ 0 ].files = dt.files;
					$form.find( 'input[name="' + cfg.modeField + '"][value="' + ( cfg.fileMode || 'file' ) + '"]' )
						.prop( 'checked', true );
					// Provenance: the original URL rides along as a form value.
					if ( !$form.find( 'input[name="wbUploadmetaSourceUrl"]' ).length ) {
						$form.append( $( '<input type="hidden" name="wbUploadmetaSourceUrl">' ).val( url ) );
					}
					$status.hide();
					// Native submit() bypasses this handler — no loop.
					$form[ 0 ].submit();
				} ).catch( function ( err ) {
					$status.text( String( err && err.message ? err.message : mw.msg( 'embeddablecontent-uploadmeta-browserfetch-failed' ) ) ).show();
				} );
				return false;
			} );
		} );
	} );
}() );
