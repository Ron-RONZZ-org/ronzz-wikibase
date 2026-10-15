/**
 * Pyodide4MediaWiki — Pyodide Web Worker body.
 *
 * This module exports a FUNCTION whose source is serialized with
 * `Function.prototype.toString()` and instantiated as a Blob worker by
 * `ext.pyodide4mediawiki.js`. It must therefore be fully self-contained (no
 * references to outer-module scope) and must not rely on ResourceLoader's
 * module wrapper.
 *
 * The worker keeps ONE interpreter alive for the page, so variables persist
 * across cells like a notebook, and it streams stdout/stderr back so output
 * appears while the code runs.
 *
 * @license GPL-2.0-or-later
 */
module.exports = function pyodide4MediaWikiWorker() {
	'use strict';

	var pyodide = null;

	function post( message ) {
		self.postMessage( message );
	}

	function describeError( err ) {
		if ( err && err.message ) {
			return String( err.message );
		}
		return String( err );
	}

	self.addEventListener( 'message', function ( event ) {
		var message = event.data;

		if ( message.type === 'init' ) {
			// The classic build defines a global loadPyodide; it resolves its
			// own assets (pyodide.asm.wasm, python_stdlib.zip, pyodide-lock.json
			// and the vendored wheels) relative to indexURL. packageBaseUrl is
			// pinned to the same local directory so loadPackage never falls
			// back to Pyodide's bundled CDN default — this wiki serves its own
			// assets (tools/install-pyodide.sh).
			try {
				importScripts( message.indexUrl + 'pyodide.js' );
			} catch ( err ) {
				post( { type: 'init-error', message: describeError( err ) } );
				return;
			}
			self.loadPyodide( {
				indexURL: message.indexUrl,
				packageBaseUrl: message.indexUrl
			} ).then( function ( instance ) {
				pyodide = instance;
				pyodide.setStdout( {
					batched: function ( line ) {
						post( { type: 'stdout', text: line + '\n' } );
					}
				} );
				pyodide.setStderr( {
					batched: function ( line ) {
						post( { type: 'stderr', text: line + '\n' } );
					}
				} );
				post( { type: 'ready' } );
			}, function ( err ) {
				post( { type: 'init-error', message: describeError( err ) } );
			} );
			return;
		}

		if ( message.type === 'run' ) {
			runBlock( message );
		}
	} );

	async function runBlock( message ) {
		if ( !pyodide ) {
			post( { type: 'error', id: message.id, message: 'Pyodide is not ready.' } );
			return;
		}

		try {
			if ( message.packages && message.packages.length ) {
				await pyodide.loadPackage( message.packages );
			}
			var result = await pyodide.runPythonAsync( message.code );

			var text = null;
			if ( result !== undefined && result !== null ) {
				try {
					text = String( result );
				} catch ( stringifyError ) {
					text = null;
				}
			}
			if ( result && typeof result.destroy === 'function' ) {
				result.destroy();
			}

			post( { type: 'result', id: message.id, result: text } );
		} catch ( err ) {
			post( { type: 'error', id: message.id, message: describeError( err ) } );
		}
	}
};
