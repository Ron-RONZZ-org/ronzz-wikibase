#!/usr/bin/env node
/**
 * Pyodide4MediaWiki — Web Worker regression test (Node, no browser).
 *
 * The Playwright suite (run_pyodide_ux_e2e.mjs) proves the page path; this
 * test proves the WORKER path directly, without a UI: it loads the real
 * serialized worker body (resources/pyodide.worker.js) against the pinned,
 * vendored Pyodide distribution and drives `init` / `run` messages the same
 * way the ResourceLoader bootstrap does.
 *
 * The regression it guards: matplotlib's Pyodide build selects its
 * DOM-bound "pyodide" backend (aliased from webagg) by default, so a
 * `plt.show()` inside the worker died with
 * `ReferenceError: window is not defined`. The worker must force Agg and
 * post each open figure back as a PNG data URL.
 *
 * Checks:
 *   1. the interpreter initialises;
 *   2. `plt.show()` produces one valid PNG image message, closes the
 *      figures (Jupyter-like semantics), and leaves stderr clean;
 *   3. `Figure.show()` does the same for a single figure;
 *   4. the selected backend is `agg`;
 *   5. a plain Python cell still runs (the hook never breaks other cells).
 *
 * Usage (from the repo root; the assets are gitignored and installed by
 * tools/install-pyodide.sh):
 *
 *     node tests/e2e/run_pyodide_worker_test.mjs [--assets DIR] [--worker FILE]
 *
 * `--assets` defaults to the checkout's
 * extensions/Pyodide4MediaWiki/resources/pyodide; pass another directory when
 * testing from a worktree (assets live in the main checkout / install dir).
 * Exit code 0 = all checks passed; 2 = assets missing; 1 = test failure.
 *
 * License: GPL-2.0-or-later
 */

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const REPO_ROOT = path.resolve( HERE, '../..' );

function arg( name, fallback ) {
	const index = process.argv.indexOf( name );
	if ( index < 0 ) {
		return fallback;
	}
	if ( index + 1 >= process.argv.length ) {
		console.error( `run_pyodide_worker_test.mjs: ${ name } needs a value` );
		process.exit( 2 );
	}
	return process.argv[ index + 1 ];
}

const ASSETS_DIR = path.resolve( arg(
	'--assets',
	path.join( REPO_ROOT, 'extensions/Pyodide4MediaWiki/resources/pyodide' )
) );
const WORKER_PATH = path.resolve( arg(
	'--worker',
	path.join( REPO_ROOT, 'extensions/Pyodide4MediaWiki/resources/pyodide.worker.js' )
) );

const LOAD_TIMEOUT = 300000;
const RUN_TIMEOUT = 240000;
const PNG_PREFIX = 'data:image/png;base64,';

for ( const required of [ 'pyodide.mjs', 'pyodide-lock.json', 'pyodide.asm.wasm' ] ) {
	if ( !existsSync( path.join( ASSETS_DIR, required ) ) ) {
		console.error( `run_pyodide_worker_test.mjs: ${ required } missing in ${ ASSETS_DIR }` );
		console.error( 'Install the assets first: tools/install-pyodide.sh' );
		process.exit( 2 );
	}
}
if ( !existsSync( WORKER_PATH ) ) {
	console.error( `run_pyodide_worker_test.mjs: worker not found: ${ WORKER_PATH }` );
	process.exit( 2 );
}

const workerBody = createRequire( import.meta.url )( WORKER_PATH );
const { loadPyodide } = await import(
	pathToFileURL( path.join( ASSETS_DIR, 'pyodide.mjs' ) ).href
);

const listeners = [];
let received = [];
const fakeSelf = {
	addEventListener( type, handler ) {
		if ( type === 'message' ) {
			listeners.push( handler );
		}
	},
	postMessage( message ) {
		received.push( message );
	}
};

// The extension serializes the worker function and supplies `loadPyodide`
// from a module import; mimic that binding here.
new Function( 'loadPyodide', 'self', `(${ workerBody.toString() })()` )(
	loadPyodide,
	fakeSelf
);

function send( message ) {
	for ( const handler of listeners ) {
		handler( { data: message } );
	}
}

function waitFor( predicate, timeout, label ) {
	return new Promise( ( resolve, reject ) => {
		const started = Date.now();
		const timer = setInterval( () => {
			const match = received.find( predicate );
			if ( match ) {
				clearInterval( timer );
				resolve( match );
			} else if ( Date.now() - started > timeout ) {
				clearInterval( timer );
				reject( new Error(
					`timed out waiting for ${ label }; got: ` +
					JSON.stringify( received.map( ( message ) => message.type ) )
				) );
			}
		}, 50 );
	} );
}

let nextId = 1;
async function runCell( code ) {
	received = [];
	const id = nextId++;
	send( { type: 'run', id, code, packages: [] } );
	const outcome = await waitFor(
		( message ) => message.type === 'result' || message.type === 'error',
		RUN_TIMEOUT,
		`run #${ id } to finish`
	);
	return {
		id,
		outcome,
		images: received.filter( ( message ) => message.type === 'image' ),
		stdout: received
			.filter( ( message ) => message.type === 'stdout' )
			.map( ( message ) => message.text )
			.join( '' ),
		stderr: received
			.filter( ( message ) => message.type === 'stderr' )
			.map( ( message ) => message.text )
			.join( '' )
	};
}

function pngSize( dataUrl ) {
	assert.ok(
		dataUrl.startsWith( PNG_PREFIX ),
		'image message must carry a PNG data URL'
	);
	const bytes = Buffer.from( dataUrl.slice( PNG_PREFIX.length ), 'base64' );
	assert.deepEqual(
		bytes.subarray( 0, 8 ),
		Buffer.from( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a ] ),
		'image payload must start with the PNG signature'
	);
	assert.ok( bytes.length > 1000, 'PNG payload looks empty' );
	return {
		width: bytes.readUInt32BE( 16 ),
		height: bytes.readUInt32BE( 20 ),
		length: bytes.length
	};
}

console.log( `[info] node ${ process.version }; assets ${ ASSETS_DIR }` );
send( { type: 'init', indexUrl: ASSETS_DIR + path.sep } );
await waitFor( ( message ) => message.type === 'ready', LOAD_TIMEOUT, 'the interpreter' );
console.log( '[ok] interpreter initialised' );

try {
	const plotted = await runCell( `
import numpy as np
import matplotlib.pyplot as plt

x = np.linspace(-3, 3, 400)
plt.figure(figsize=(7, 4.2))
plt.plot(x, np.exp(-0.25 * x**2), "-o", markevery=40, label="y1")
plt.plot(x, np.exp(-0.5 * x**2), "--s", markevery=40, label="y2")
plt.title("Two Gaussian densities on $[-3, 3]$")
plt.grid(alpha=0.3)
plt.legend()
plt.show()
print("figures", plt.get_fignums())
` );
	assert.equal( plotted.outcome.type, 'result', `plt.show() raised: ${ plotted.outcome.message }` );
	assert.equal( plotted.images.length, 1, 'plt.show() must post exactly one figure' );
	const first = pngSize( plotted.images[ 0 ].data );
	assert.ok( first.width > 100 && first.height > 100, 'figure has an implausible size' );
	assert.match( plotted.stdout, /figures \[\]/, 'plt.show() must close the shown figures' );
	assert.equal( plotted.stderr, '', `unexpected stderr: ${ plotted.stderr }` );
	console.log( `[ok] plt.show() → ${ first.width }×${ first.height } PNG (${ first.length } bytes)` );

	const figureShown = await runCell( `
import matplotlib.pyplot as plt

fig, ax = plt.subplots()
ax.plot([1, 2, 3])
fig.show()
print("figures", plt.get_fignums())
` );
	assert.equal( figureShown.outcome.type, 'result', `Figure.show() raised: ${ figureShown.outcome.message }` );
	assert.equal( figureShown.images.length, 1, 'Figure.show() must post one figure' );
	pngSize( figureShown.images[ 0 ].data );
	assert.match( figureShown.stdout, /figures \[\]/, 'Figure.show() must close its figure' );
	console.log( '[ok] Figure.show() → PNG' );

	const backend = await runCell( 'import matplotlib\nprint("backend", matplotlib.get_backend())\n' );
	assert.equal( backend.outcome.type, 'result', `backend check raised: ${ backend.outcome.message }` );
	assert.match( backend.stdout, /backend agg/, `expected the agg backend, got: ${ backend.stdout }` );
	console.log( '[ok] backend is agg' );

	const plain = await runCell( 'print(6 * 7)\n' );
	assert.equal( plain.outcome.type, 'result', `plain cell raised: ${ plain.outcome.message }` );
	assert.match( plain.stdout, /42/, 'plain stdout must still stream' );
	console.log( '[ok] plain cell still runs' );
} catch ( error ) {
	console.error( `\nFAIL: ${ error && error.message ? error.message : error }` );
	process.exit( 1 );
}

console.log( '\nall Pyodide4MediaWiki worker checks passed' );
