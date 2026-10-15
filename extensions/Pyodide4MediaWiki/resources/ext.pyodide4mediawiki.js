/**
 * Pyodide4MediaWiki — client-side runner for `class="pyodide"` code blocks.
 *
 * The server queues this module (and its config) only on pages that actually
 * contain a `pyodide` block. Nothing heavyweight happens until the reader
 * clicks Run: the Pyodide interpreter (a ~10 MB WebAssembly build) and the
 * vendored Python packages are fetched then, from the wiki's own origin.
 *
 * The interpreter runs in a Web Worker (created from the serialized worker
 * body) so loading and executing Python never freezes the page. One worker is
 * shared per page, so variables persist across cells.
 *
 * @license GPL-2.0-or-later
 */
'use strict';

var workerBody = require( './pyodide.worker.js' );

var CLASS_NAME = 'pyodide';
var indexUrl = mw.config.get( 'wgPyodide4MediaWikiIndexUrl' );
var packages = mw.config.get( 'wgPyodide4MediaWikiPackages' ) || [];
var requireLogin = mw.config.get( 'wgPyodide4MediaWikiRequireLogin' ) !== false;
var userName = mw.config.get( 'wgUserName' );

var worker = null;
var workerPromise = null;
var workerReadyResolve = null;
var workerReadyReject = null;
var activeStream = null;
var runResolve = null;
var runReject = null;
var running = false;

function canRun() {
	return !requireLogin || ( userName !== null && userName !== undefined );
}

function codeOf( block ) {
	var node = block.matches( 'pre, code' ) ? block : block.querySelector( 'pre, code' );
	var text = ( node || block ).textContent;
	// The highlighter leaves a trailing newline; keep internal blank lines.
	return text.replace( /\n$/, '' );
}

function appendStream( text ) {
	if ( activeStream ) {
		activeStream.appendChild( document.createTextNode( text ) );
	}
}

function setButtonsDisabled( disabled ) {
	var buttons = document.querySelectorAll( '.pyodide4mediawiki-run' );
	for ( var i = 0; i < buttons.length; i++ ) {
		buttons[ i ].disabled = disabled;
	}
}

function onWorkerMessage( event ) {
	var message = event.data;

	if ( message.type === 'stdout' || message.type === 'stderr' ) {
		appendStream( message.text );
		return;
	}
	if ( message.type === 'ready' ) {
		if ( workerReadyResolve ) {
			workerReadyResolve();
			workerReadyResolve = null;
			workerReadyReject = null;
		}
		return;
	}
	if ( message.type === 'init-error' ) {
		if ( workerReadyReject ) {
			workerReadyReject( new Error( message.message ) );
			workerReadyResolve = null;
			workerReadyReject = null;
		}
		return;
	}
	if ( message.type === 'result' || message.type === 'error' ) {
		var resolve = runResolve;
		var reject = runReject;
		runResolve = null;
		runReject = null;
		if ( !resolve ) {
			return;
		}
		if ( message.type === 'error' ) {
			reject( new Error( message.message ) );
		} else {
			resolve( message.result );
		}
	}
}

function ensureWorker() {
	if ( workerPromise ) {
		return workerPromise;
	}
	workerPromise = new Promise( function ( resolve, reject ) {
		var source;
		try {
			source = '(' + workerBody.toString() + ')();';
			worker = new Worker( URL.createObjectURL(
				new Blob( [ source ], { type: 'application/javascript' } )
			) );
		} catch ( err ) {
			reject( err );
			return;
		}
		workerReadyResolve = resolve;
		workerReadyReject = reject;
		worker.addEventListener( 'message', onWorkerMessage );
		worker.addEventListener( 'error', function ( event ) {
			var err = event.error || new Error( event.message || 'Pyodide worker error' );
			if ( workerReadyReject ) {
				workerReadyReject( err );
				workerReadyResolve = null;
				workerReadyReject = null;
			}
			if ( runReject ) {
				runReject( err );
				runResolve = null;
				runReject = null;
			}
		} );
		worker.postMessage( { type: 'init', indexUrl: indexUrl } );
	} );
	return workerPromise;
}

function dispatchRun( code ) {
	return new Promise( function ( resolve, reject ) {
		runResolve = resolve;
		runReject = reject;
		worker.postMessage( {
			type: 'run',
			id: Date.now(),
			code: code,
			packages: packages
		} );
	} );
}

function renderResult( output, text ) {
	if ( text === null || text === undefined || text === '' ) {
		return;
	}
	var node = document.createElement( 'div' );
	node.className = 'pyodide4mediawiki-result';
	node.textContent = text;
	output.appendChild( node );
}

function renderError( output, message ) {
	var node = document.createElement( 'div' );
	node.className = 'pyodide4mediawiki-error';
	node.textContent = mw.msg( 'pyodide4mediawiki-error' ) + ': ' + message;
	output.appendChild( node );
}

function runBlock( block, output, button, status ) {
	if ( running ) {
		return;
	}
	running = true;
	setButtonsDisabled( true );

	output.hidden = false;
	output.textContent = '';
	var stream = document.createElement( 'pre' );
	stream.className = 'pyodide4mediawiki-stream';
	output.appendChild( stream );
	activeStream = stream;

	status.textContent = mw.msg( 'pyodide4mediawiki-loading' );

	ensureWorker().then( function () {
		status.textContent = mw.msg( 'pyodide4mediawiki-running' );
		return dispatchRun( codeOf( block ) );
	} ).then( function ( result ) {
		status.textContent = '';
		renderResult( output, result );
	} ).catch( function ( err ) {
		status.textContent = '';
		renderError( output, err && err.message ? err.message : String( err ) );
	} ).then( function () {
		activeStream = null;
		running = false;
		setButtonsDisabled( false );
	} );
}

function addControls( block ) {
	var toolbar = document.createElement( 'div' );
	toolbar.className = 'pyodide4mediawiki-toolbar';

	if ( !canRun() ) {
		var note = document.createElement( 'span' );
		note.className = 'pyodide4mediawiki-login-note';
		note.textContent = mw.msg( 'pyodide4mediawiki-login-required' );
		toolbar.appendChild( note );
		block.insertAdjacentElement( 'afterend', toolbar );
		return;
	}

	var output = document.createElement( 'div' );
	output.className = 'pyodide4mediawiki-output';
	output.hidden = true;
	output.setAttribute( 'aria-label', mw.msg( 'pyodide4mediawiki-output' ) );

	var button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'pyodide4mediawiki-run';
	button.textContent = mw.msg( 'pyodide4mediawiki-run' );

	var status = document.createElement( 'span' );
	status.className = 'pyodide4mediawiki-status';

	button.addEventListener( 'click', function () {
		runBlock( block, output, button, status );
	} );

	toolbar.appendChild( button );
	toolbar.appendChild( status );
	block.insertAdjacentElement( 'afterend', toolbar );
	toolbar.insertAdjacentElement( 'afterend', output );
}

function enhanceAll( root ) {
	if ( !root || typeof root.querySelectorAll !== 'function' ) {
		return;
	}
	var blocks = root.querySelectorAll( '.' + CLASS_NAME );
	for ( var i = 0; i < blocks.length; i++ ) {
		var block = blocks[ i ];
		if ( block.getAttribute( 'data-pyodide4mediawiki-bound' ) ) {
			continue;
		}
		block.setAttribute( 'data-pyodide4mediawiki-bound', '1' );
		addControls( block );
	}
}

if ( indexUrl ) {
	$( function () {
		enhanceAll( document );
	} );
	mw.hook( 'wikipage.content' ).add( function ( $content ) {
		enhanceAll( $content && $content.length ? $content[ 0 ] : document );
	} );
}
