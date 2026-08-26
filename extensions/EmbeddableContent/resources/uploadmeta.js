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

	/**
	 * Cap on the fetched description — matches the instance's raised term
	 * limit (string-limits multilang length 2000). Mirrors the PHP
	 * CommonsMetadataParser::DESCRIPTION_CAP.
	 */
	var DESCRIPTION_CAP = 2000;

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

	/** Commons extmetadata values are HTML fragments — strip + collapse.
	 * Optionally length-caps at $cap (default 250), cutting at the last
	 * sentence-ending punctuation inside the cap when one exists past the
	 * first 100 chars — a fetched summary never ends mid-sentence. Mirrors
	 * the PHP CommonsMetadataParser. */
	function cleanText( value, cap ) {
		if ( !value ) {
			return '';
		}
		cap = cap || 250;
		var text = String( value );
		if ( typeof document !== 'undefined' ) {
			var div = document.createElement( 'div' );
			div.innerHTML = text;
			text = div.textContent || div.innerText || '';
		}
		text = text.replace( /\s+/g, ' ' ).trim();
		if ( text.length <= cap ) {
			return text;
		}
		var slice = text.slice( 0, cap );
		var last = -1;
		[ '. ', '! ', '? ' ].forEach( function ( sep ) {
			var pos = slice.lastIndexOf( sep );
			if ( pos > last ) {
				last = pos;
			}
		} );
		if ( last >= 100 ) {
			return slice.slice( 0, last + 1 );
		}
		return slice;
	}

	/** Destination-file-name normalization for the fetched ObjectName:
	 * lowercase, any word separator (spaces, underscores, camelCase/
	 * PascalCase boundaries, existing dashes) → single dashes, and
	 * MediaWiki-illegal filename characters (#<>[]|{}:) dropped; a trailing
	 * extension is preserved (lowercased). Unicode-aware so accented names
	 * like "École" survive. Mirrors the PHP
	 * CommonsMetadataParser::normalizeDestName. Empty when nothing usable
	 * remains (the field is left untouched). */
	function normalizeDestName( name ) {
		if ( !name ) {
			return '';
		}
		name = String( name ).trim();
		if ( !name ) {
			return '';
		}
		var ext = '';
		var base = name;
		var dot = name.lastIndexOf( '.' );
		if ( dot > 0 && dot < name.length - 1 ) {
			ext = name.slice( dot + 1 );
			base = name.slice( 0, dot );
		}
		// camelCase / PascalCase boundaries first — must run before
		// lowercasing.
		base = base.replace( /([\p{L}\p{N}])([\p{Lu}])/gu, '$1 $2' )
			.toLowerCase()
			// Any run of non-letter/digit (space, underscore, dot, dash,
			// illegal chars, …) is one word separator → a single dash.
			.replace( /[^\p{L}\p{N}]+/gu, '-' )
			.replace( /^-+|-+$/g, '' );
		if ( !base ) {
			return '';
		}
		return ext ? base + '.' + ext.toLowerCase() : base;
	}

	/**
	 * Resolve a form field target to its REAL <input>. The config ids are
	 * the HTMLForm field NAMES ("wpUploadFileURL", "wpportraitLicense"):
	 *  - a php-mode form (Special:Upload's core fields) renders the <input>
	 *    with that exact id;
	 *  - an OOUI form (the Add* pages) renders the widget wrapper with a
	 *    "mw-input-…" id and the <input> with an auto-generated id, but the
	 *    field NAME is stable — so fall back to input[name=…];
	 *  - OOUIComboboxField (Special:Upload's license) wraps the input in an
	 *    OOUI widget, so also descend into the wrapper's inner input.
	 * Without the fallback the Add* validate button never rendered and the
	 * Special:Upload license autofill silently no-oped (the id mismatch).
	 */
	function findInput( id ) {
		if ( !id ) {
			return null;
		}
		var el = document.getElementById( id );
		if ( el && el.tagName === 'INPUT' ) {
			return el;
		}
		if ( el ) {
			var inner = el.querySelector( 'input' );
			if ( inner ) {
				return inner;
			}
		}
		return document.querySelector( 'input[name="' + id + '"]' ) || el || null;
	}

	function fieldVal( cfg, key ) {
		var id = cfg.targets && cfg.targets[ key ];
		var el = findInput( id );
		if ( !el ) {
			return null;
		}
		var set = function ( value ) {
			el.value = String( value || '' );
			// OOUI's TextInputWidget binds 'keydown mouseup cut paste change
			// input select' on its input — a native 'change' re-syncs the
			// widget's internal value (and 'input' covers older bindings).
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		};
		var get = function () { return el.value; };
		return { el: el, set: set, get: get };
	}

	/** Pure normalized similarity between a fetched label and a candidate
	 * label (mirrors the PHP EntityLabelMatcher::scorePair): exact → 1.0,
	 * prefix (≥6 chars) → 0.9, token containment → 0.85, Levenshtein
	 * near-miss (≥0.8) → the similarity, else 0.
	 */
	function labelScore( fetched, candidate ) {
		var f = compact( fetched );
		var c = compact( candidate );
		if ( !f || !c ) {
			return 0;
		}
		if ( f === c ) {
			return 1;
		}
		if ( f.length >= 6 && c.length >= 6 && ( c.startsWith( f ) || f.startsWith( c ) ) ) {
			return 0.9;
		}
		var fw = words( fetched );
		var cw = words( candidate );
		if ( fw.length && cw.length ) {
			var shorter = fw.length <= cw.length ? fw : cw;
			var longer = fw.length <= cw.length ? cw : fw;
			if ( shorter.every( function ( w ) { return longer.indexOf( w ) !== -1; } ) ) {
				return 0.85;
			}
		}
		var maxLen = Math.max( f.length, c.length );
		if ( maxLen > 0 ) {
			var sim = 1 - ( levenshtein( f, c ) / maxLen );
			if ( sim >= 0.8 ) {
				return sim;
			}
		}
		return 0;
	}

	/** Lowercased, punctuation/parenthetical-stripped compact form. */
	function compact( label ) {
		return String( label || '' ).toLowerCase()
			.replace( /\s*\([^)]*\)\s*$/u, '' )
			.replace( /[\p{P}\p{S}\s]+/gu, '' );
	}

	/** Significant words (≥2 chars) of a label. */
	function words( label ) {
		var m = String( label || '' ).toLowerCase().match( /[\p{L}\p{N}]+/gu ) || [];
		return m.filter( function ( w ) { return w.length >= 2; } );
	}

	function levenshtein( a, b ) {
		if ( a === b ) {
			return 0;
		}
		if ( !a.length ) {
			return b.length;
		}
		if ( !b.length ) {
			return a.length;
		}
		var prev = [];
		var cur = [];
		for ( var j = 0; j <= b.length; j++ ) {
			prev[ j ] = j;
		}
		for ( var i = 1; i <= a.length; i++ ) {
			cur[ 0 ] = i;
			for ( var j = 1; j <= b.length; j++ ) {
				cur[ j ] = Math.min(
					prev[ j ] + 1,
					cur[ j - 1 ] + 1,
					prev[ j - 1 ] + ( a[ i - 1 ] === b[ j - 1 ] ? 0 : 1 )
				);
			}
			prev = cur.slice();
		}
		return prev[ b.length ];
	}

	/** Minimum score for a license match worth confirming (PHP
	 * EntityLabelMatcher::GOOD_MATCH_THRESHOLD). */
	var MATCH_THRESHOLD = 0.75;

	/**
	 * Resolve a license LABEL to a candidate item via the instance's
	 * wbsearchentities (the combobox submits item ids). Returns the best
	 * candidate scoring >= MATCH_THRESHOLD, or null when nothing matches.
	 * The instance's search is case-sensitive (T242644), so the raw and
	 * title-cased queries run in parallel, like entitysuggest.js.
	 */
	function matchLicense( label, onReady ) {
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
			var best = null;
			var seen = {};
			( results || [] ).forEach( function ( data ) {
				( data.search || [] ).forEach( function ( row ) {
					if ( seen[ row.id ] ) {
						return;
					}
					seen[ row.id ] = true;
					var score = labelScore( label, row.label || '' );
					if ( score >= MATCH_THRESHOLD && ( !best || score > best.score ) ) {
						best = { id: row.id, label: row.label || row.id, score: score };
					}
				} );
			} );
			onReady( best );
		} ).catch( function () {
			onReady( null );
		} );
	}

	/**
	 * The autofill-confirm banner for a license field: "{field} fetched
	 * from source: {value}, we think this corresponds to {label} ({id})."
	 * with [Yes, that's right] / [No, let me correct]. The field is already
	 * filled; "No" clears it and focuses the combobox.
	 */
	function showLicenseConfirm( cfg, licenseField, fetched, best ) {
		var $input = $( licenseField.el );
		var $banner = $( '<div class="wb-entity-confirm"></div>' )
			.append( $( '<span class="wb-entity-confirm-line"></span>' ).text(
				mw.msg( 'embeddablecontent-entityconfirm-line',
					cfg.licenseLabel || mw.msg( 'embeddablecontent-upload-license' ),
					fetched, best.label, best.id )
			) )
			.append( $( '<span class="wb-entity-confirm-actions"></span>' )
				.append( $( '<button type="button" class="wb-entity-confirm-yes"></button>' )
					.text( mw.msg( 'embeddablecontent-entityconfirm-yes' ) ) )
				.append( $( '<button type="button" class="wb-entity-confirm-no"></button>' )
					.text( mw.msg( 'embeddablecontent-entityconfirm-no' ) ) ) );
		$banner.find( '.wb-entity-confirm-yes' ).on( 'click', function () {
			$banner.remove();
		} );
		$banner.find( '.wb-entity-confirm-no' ).on( 'click', function () {
			licenseField.set( '' );
			$( licenseField.el ).trigger( 'focus' );
			$banner.remove();
		} );
		// Place below the field: inside a php-mode table cell, or after an
		// OOUI field layout.
		var $host = $input.closest( 'td.mw-input, .oo-ui-fieldLayout' ).first();
		if ( $host.is( 'td' ) || !$host.length ) {
			$input.after( $banner );
		} else {
			$host.after( $banner );
		}
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

	/** Canonical lowercase extension for a MIME type ('' when unknown). */
	function extensionForMime( mime ) {
		if ( !mime ) {
			return '';
		}
		mime = String( mime ).split( ';' )[ 0 ].trim().toLowerCase();
		var map = {
			'image/jpeg': 'jpg', 'image/pjpeg': 'jpg', 'image/png': 'png',
			'image/gif': 'gif', 'image/webp': 'webp', 'image/svg+xml': 'svg',
			'image/tiff': 'tiff', 'image/x-tiff': 'tiff',
			'application/pdf': 'pdf', 'application/epub+zip': 'epub',
			'application/djvu': 'djvu',
			'video/mp4': 'mp4', 'video/webm': 'webm', 'video/ogg': 'ogv',
			'video/quicktime': 'mov',
			'audio/mpeg': 'mp3', 'audio/ogg': 'oga', 'audio/wav': 'wav',
			'audio/x-wav': 'wav', 'audio/x-m4a': 'm4a', 'audio/mp4': 'm4a',
			'audio/flac': 'flac', 'audio/opus': 'opus'
		};
		return map[ mime ] || '';
	}

	/** Extension from a URL's pathname ('' when none). */
	function extensionFromUrl( url ) {
		if ( !url ) {
			return '';
		}
		try {
			var m = String( new URL( url ).pathname ).match( /\.([A-Za-z0-9]{1,8})$/ );
			return m ? m[ 1 ].toLowerCase() : '';
		} catch ( e ) {
			return '';
		}
	}

	function applyMeta( cfg, meta, $preview ) {
		var name = fieldVal( cfg, 'name' );
		if ( name && meta.name ) {
			// Destination-file name: normalized (lowercase, space→dash) —
			// the fetched ObjectName is Title Case with spaces and usually
			// has NO extension ("National Geographic Society …"). Append the
			// canonical extension from the MIME type (fallback: the source
			// URL's own extension) so the dest-name field is complete.
			var normalized = normalizeDestName( meta.name );
			if ( normalized && normalized.indexOf( '.' ) === -1 ) {
				var ext = extensionForMime( meta.mime ) || extensionFromUrl( meta.sourceUrl );
				if ( ext ) {
					normalized = normalized + '.' + ext;
				}
			}
			if ( normalized ) {
				name.set( normalized );
			}
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
			// Autofill-confirm: the fetched license label is matched against
			// the instance's license items (fuzzy — "CC BY-SA 4.0
			// International" still hits "CC BY-SA 4.0"); a good match fills
			// the field AND asks the user to confirm or correct. No match →
			// the field stays empty (the current flow).
			matchLicense( meta.license, function ( best ) {
				if ( !best ) {
					return;
				}
				license.set( best.id );
				showLicenseConfirm( cfg, license, meta.license, best );
			} );
		}

		// Preview: <img> for image types, a file-type icon badge for
		// PDF/video/audio/other, plus pixel size (images) + byte size.
		var html = '';
		var mime = meta.mime ? String( meta.mime ).split( ';' )[ 0 ].trim().toLowerCase() : '';
		var isImage = mime.indexOf( 'image/' ) === 0;
		if ( isImage ) {
			if ( meta.thumbUrl || meta.sourceUrl ) {
				html += '<img src="' + mw.html.escape( meta.thumbUrl || meta.sourceUrl ) + '" alt="" loading="lazy">';
			}
		} else if ( meta.mime ) {
			// File-type icon: a small badge with the canonical extension
			// (falls back to the MIME's subtype when unmapped).
			var fext = extensionForMime( meta.mime ) ||
				( mime.split( '/' )[ 1 ] || 'file' ).replace( /[^a-z0-9]/g, '' );
			html += '<span class="wb-uploadmeta-fileicon" title="' + mw.html.escape( meta.mime ) + '">'
				+ mw.html.escape( fext || 'file' ) + '</span>';
		}
		var bits = [];
		if ( isImage && meta.width && meta.height ) {
			bits.push( meta.width + ' × ' + meta.height + ' px' );
		}
		if ( meta.fileSize ) {
			bits.push( formatBytes( meta.fileSize ) );
		}
		if ( meta.mime && !isImage ) {
			bits.push( meta.mime );
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
				'&prop=imageinfo&iiprop=extmetadata%7Csize%7Curl%7Cmime&format=json&formatversion=2&titles=' +
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
					description: cleanText( ext.ImageDescription && ext.ImageDescription.value, DESCRIPTION_CAP ),
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

	/**
	 * Wires ONE .wb-uploadmeta span: injects the Validate button + status +
	 * preview next to its URL field and attaches the submit-time Wikimedia
	 * blob fallback to the form. Idempotent — spans already holding a
	 * button (or whose URL field is currently absent) are skipped.
	 */
	function wire( $wrapper ) {
		var cfg = $wrapper.data( 'config' ) || {};
		if ( !cfg.urlField || $wrapper.find( '.wb-uploadmeta-validate' ).length ) {
			return;
		}
		// findInput resolves the URL field's real <input> (OOUI widget
		// wrapper or php-mode input; id → inner input → name fallback).
		var $url = $( findInput( cfg.urlField ) );
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
		// The submit handler is per-FORM, not per-span: a hide-if
		// re-insertion re-wires the span, and re-attaching the handler would
		// fire the blob fallback several times on one submit.
		if ( $form.length && $form.data( 'wbUploadmetaWired' ) ) {
			return;
		}
		if ( $form.length ) {
			$form.data( 'wbUploadmetaWired', true );
		}
		$form.on( 'submit', function ( e ) {
			// Resolve the CURRENT field elements at submit time — the OOUI
			// hide-if mechanism removes the collapsed fields from the DOM
			// and re-inserts fresh ones when the section opens, so an
			// element captured at wiring time may be DETACHED by the time
			// the user submits. The stale closure made the Wikimedia blob
			// fallback read an empty URL and silently skip, letting the
			// server-side UploadFromUrl draw "unreachable or unsupported
			// URL" (Special:AddCollective logo report).
			var $url = $( findInput( cfg.urlField ) );
			if ( !$url.length ) {
				return true;
			}
			var url = String( $url.val() || '' ).trim();
			// isWikimediaHost() takes a HOSTNAME — the full URL never
			// matches (a file URL ends with its name, not
			// .wikimedia.org). Passing the full URL here made the blob
			// fallback never fire and the server-side UploadFromUrl drew
			// the Wikimedia 429 (fceb99d). Parse first, mirroring
			// extractFileTitle().
			var host;
			try {
				host = new URL( url ).hostname;
			} catch ( err ) {
				return true;
			}
			if ( !host || !isWikimediaHost( host ) || !cfg.fileField || !cfg.modeField ) {
				return true;
			}
			// The URL-mode radio value differs across surfaces:
			//  - Special:Upload (php-mode) renders CHECKED radios named
			//    'wpSourceType' with values 'Url'/'File';
			//  - the Add* pages (OOUI) strip the name from the visible
			//    radios and carry the current value in the widget's hidden
			//    value input (name='wplogoMode', no type attribute — it is
			//    class-hidden, not type=hidden) — `:checked` never matches
			//    it, so without the fallback the Wikimedia blob conversion
			//    silently skipped and the server-side UploadFromUrl drew
			//    Wikimedia's 403/429 ("unreachable or unsupported URL" on
			//    the AddCollective logo).
			var modeVal = String(
				$form.find( 'input[name="' + cfg.modeField + '"]:checked' ).val()
				|| $form.find( 'input[name="' + cfg.modeField + '"]:not([type="radio"]):not([type="checkbox"])' ).val()
				|| ''
			).toLowerCase();
			if ( modeVal !== 'url' ) {
				return true;
			}
			// The status/preview live on the CURRENT span (the latest
			// wiring's) — resolve them from the form at submit time too.
			var $status = $form.find( '.wb-uploadmeta-status' ).first();
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
				// Switch the mode radio to 'file' FIRST: while url mode is
				// active the OOUI hide-if keeps the FILE input OUT of the
				// DOM — it re-appears only after the radio change. Wait for
				// it (poll, up to ~2 s) before setting the files.
				$form.find( 'input[name="' + cfg.modeField + '"][value="' + ( cfg.fileMode || 'file' ) + '"]' )
					.prop( 'checked', true );
				var fillAndSubmit = function ( tries ) {
					var $file = $( findInput( cfg.fileField ) );
					if ( !$file.length ) {
						if ( tries <= 0 ) {
							$status.text( mw.msg( 'embeddablecontent-uploadmeta-browserfetch-failed' ) ).show();
							return;
						}
						setTimeout( function () { fillAndSubmit( tries - 1 ); }, 50 );
						return;
					}
					var name = url.split( '/' ).pop().split( '?' )[ 0 ] || 'image';
					var file = new File( [ blob ], name, { type: blob.type || 'application/octet-stream' } );
					var dt = new DataTransfer();
					dt.items.add( file );
					$file.prop( 'disabled', false );
					$file[ 0 ].files = dt.files;
					// Provenance: the original URL rides along as a form value.
					if ( !$form.find( 'input[name="wbUploadmetaSourceUrl"]' ).length ) {
						$form.append( $( '<input type="hidden" name="wbUploadmetaSourceUrl">' ).val( url ) );
					}
					// The native submit() drops the submit BUTTON's name/value
					// (only a real click sends it), and Special:Upload's core
					// gates processing on the button: UploadForm sets
					// setSubmitName('wpUpload') and loadRequest() only
					// proceeds when getCheck('wpUpload') — without it the
					// resubmit re-renders the form ("page refreshes, nothing
					// uploaded"). Replicate the submit button as a hidden
					// field so the converted file upload actually processes.
					$form.find( 'input[type="submit"], button[type="submit"]' ).each( function () {
						var $btn = $( this );
						var btnName = $btn.attr( 'name' );
						if ( !btnName ) {
							return;
						}
						if ( !$form.find( 'input[type="hidden"][name="' + btnName + '"]' ).length ) {
							$form.append( $( '<input type="hidden">' ).attr( 'name', btnName ).val( $btn.val() || '1' ) );
						}
					} );
					$status.hide();
					// Native submit() bypasses this handler — no loop.
					$form[ 0 ].submit();
				};
				fillAndSubmit( 40 );
			} ).catch( function ( err ) {
				$status.text( String( err && err.message ? err.message : mw.msg( 'embeddablecontent-uploadmeta-browserfetch-failed' ) ) ).show();
			} );
			return false;
		} );
	}

	/** Wires every .wb-uploadmeta span currently in the DOM. */
	function wireAll() {
		$( '.wb-uploadmeta' ).each( function () {
			wire( $( this ) );
		} );
	}

	mw.loader.using( 'oojs-ui' ).then( function () {
		wireAll();
		// The Add* portrait/logo sections are hide-if COLLAPSED: OOUI
		// REMOVES the hidden field — and with it the wiring span — from the
		// DOM on load, and re-inserts it when the "I will upload a …" toggle
		// opens. The initial wireAll() therefore finds nothing to wire on
		// those fields; a MutationObserver re-wires any span that appears
		// later (idempotent — a span already holding a button is skipped).
		if ( typeof MutationObserver !== 'undefined' ) {
			new MutationObserver( function () {
				wireAll();
			} ).observe( document.body, { childList: true, subtree: true } );
		}
	} );
}() );
