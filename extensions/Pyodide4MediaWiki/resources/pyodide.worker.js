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
 * appears while the code runs. Matplotlib figures are rasterised to PNG data
 * URLs and posted back too (the DOM-bound Pyodide backend cannot run here).
 *
 * @license GPL-2.0-or-later
 */
module.exports = function pyodide4MediaWikiWorker() {
	'use strict';

	var pyodide = null;
	var matplotlibReady = false;

	// matplotlib's Pyodide build defaults to its DOM-bound "pyodide" backend
	// (aliased from webagg), which needs `window` and therefore cannot run in
	// this worker. Force the headless Agg backend, and make plt.show() /
	// Figure.show() rasterise each open figure to a PNG data URL that the page
	// renders below the block. Installed lazily, only when the code imports
	// matplotlib, so pages that never plot download nothing extra.
	var MATPLOTLIB_SETUP = [
		'import base64 as _wbm_base64',
		'import io as _wbm_io',
		'import matplotlib as _wbm_mpl',
		'_wbm_mpl.use( "agg" )',
		'import warnings as _wbm_warnings',
		// matplotlib 3.10.8's backend_agg passes float coordinates to the
		// compiled renderer internally (an upstream deprecation, not the
		// user's code), so silence just that message; other warnings stay
		// visible.
		'_wbm_warnings.filterwarnings( "ignore", message=r"The \\w+ parameter as float was deprecated.*", category=_wbm_mpl.MatplotlibDeprecationWarning )',
		'import matplotlib.pyplot as _wbm_plt',
		'from matplotlib.figure import Figure as _wbm_Figure',
		'',
		'def _wbm_encode( figure ):',
		'    buffer = _wbm_io.BytesIO()',
		'    figure.savefig( buffer, format="png", bbox_inches="tight" )',
		'    return "data:image/png;base64," + _wbm_base64.b64encode( buffer.getvalue() ).decode( "ascii" )',
		'',
		'def _wbm_show_all( *args, **kwargs ):',
		'    for number in _wbm_plt.get_fignums():',
		'        pyodide4mediawiki_post_image( _wbm_encode( _wbm_plt.figure( number ) ) )',
		'    _wbm_plt.close( "all" )',
		'',
		'def _wbm_show_figure( self, *args, **kwargs ):',
		'    pyodide4mediawiki_post_image( _wbm_encode( self ) )',
		'    _wbm_plt.close( self )',
		'',
		'_wbm_plt.show = _wbm_show_all',
		'_wbm_Figure.show = _wbm_show_figure'
	].join( '\n' );

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
				// The Pyodide matplotlib build would otherwise select webagg,
				// which is aliased to the DOM-bound backend_pyodide; pin Agg
				// before any cell can import matplotlib, in case the lazy
				// installer below is bypassed (e.g. a dynamic import).
				pyodide.runPython( 'import os; os.environ[ "MPLBACKEND" ] = "agg"' );
				pyodide.globals.set( 'pyodide4mediawiki_post_image', function ( dataUrl ) {
					post( { type: 'image', data: String( dataUrl ) } );
				} );
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

	function configureMatplotlib() {
		if ( matplotlibReady ) {
			return;
		}
		// loadPackagesFromImports unpacks (but does not import) the wheels the
		// code needs, so the spec exists BEFORE the user's first pyplot import.
		var installed = pyodide.runPython(
			'import importlib.util\n' +
			'importlib.util.find_spec( "matplotlib" ) is not None'
		);
		if ( installed ) {
			pyodide.runPython( MATPLOTLIB_SETUP );
			matplotlibReady = true;
		}
	}

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
			configureMatplotlib();

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
