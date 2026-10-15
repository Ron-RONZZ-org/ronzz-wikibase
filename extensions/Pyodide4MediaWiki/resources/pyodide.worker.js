/**
 * Pyodide4MediaWiki — Pyodide Web Worker body.
 *
 * This module exports a FUNCTION whose source is serialized with
 * `Function.prototype.toString()` and instantiated by `ext.pyodide4mediawiki.js`
 * as a **module worker** from a Blob URL. It must therefore be fully
 * self-contained (no references to outer-module scope) except for the
 * `loadPyodide` binding, which the bootstrap injects as a module-level import
 * of `pyodide.mjs` ahead of the serialized function.
 *
 * Pyodide requires a module worker (`pyodide.js`'s classic-worker build was
 * dropped) — the bootstrap therefore creates the worker with `{ type: 'module' }`
 * and prepends `import { loadPyodide } from "<indexUrl>pyodide.mjs";`.
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
			// packageBaseUrl is pinned to the wiki's own origin alongside
			// indexURL so loadPackage never falls back to Pyodide's bundled
			// CDN default — this wiki serves its own assets
			// (tools/install-pyodide.sh).
			loadPyodide( {
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
			// Notebook semantics: load only the packages the code imports, so a
			// cell that imports nothing (e.g. `print(1 + 1)`) pulls no packages
			// and `import numpy` pulls just numpy — from the vendored offline
			// set. Configured `packages` (above) are preloaded on top.
			await pyodide.loadPackagesFromImports( message.code );

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
