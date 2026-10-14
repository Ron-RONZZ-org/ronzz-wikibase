/**
 * GeoGebra sandboxed player.
 *
 * Served from a cookie-less origin (e.g. https://ggb.ronzz.org/player.html),
 * embedded by the wiki in a sandboxed <iframe>. It loads the self-hosted
 * GeoGebra app (GeoGebra/deployggb.js + GeoGebra/HTML5/5.0/web3d/) and builds
 * the applet from either:
 *   - the `file` query parameter — a `.ggb` fetched cross-origin (CORS) from
 *     the wiki (the `[[File:x.ggb]]` media handler); or
 *   - the `commands` query parameter — RAW GeoGebra commands, one per line,
 *     evaluated through the applet's `evalCommand` (the `<geogebra>` parser
 *     tag, no uploaded file needed).
 *
 * Hardening (Layer 1 — see docs/decisions/geogebra.md):
 *   disableJavaScript: true  — GeoGebra does not run JavaScript from material files
 *   useBrowserForJS: true    — ignores ggbOnInit / object JS update scripts from the file
 * Combined with the iframe origin isolation, a malicious .ggb (or a malicious
 * command set) cannot reach the wiki's DOM, cookies or session.
 *
 * @license GPL-2.0-or-later
 */
( function () {
	'use strict';

	var params = new URLSearchParams( window.location.search );
	var file = params.get( 'file' ) || '';
	var commands = params.get( 'commands' ) || '';
	var width = parseInt( params.get( 'w' ), 10 ) || 800;
	var height = parseInt( params.get( 'h' ), 10 ) || 600;
	var app = params.get( 'app' ) || 'classic';

	function fail( message ) {
		var box = document.getElementById( 'ggb-error' );
		box.hidden = false;
		box.textContent = message;
	}

	var hasFile = /^https?:\/\//i.test( file );
	var hasCommands = commands.trim() !== '';
	if ( !hasFile && !hasCommands ) {
		fail( 'No GeoGebra file or commands were provided.' );
		return;
	}

	var script = document.createElement( 'script' );
	script.src = 'GeoGebra/deployggb.js';
	script.onload = function () {
		/* global GGBApplet */
		var config = {
			appName: app,
			width: width,
			height: height,
			showMenuBar: false,
			showToolBar: true,
			showAlgebraInput: true,
			enableRightClick: false,
			enableFileFeatures: false,
			useBrowserForJS: true,
			disableJavaScript: true
		};
		if ( hasFile ) {
			config.filename = file;
		}
		if ( hasCommands ) {
			config.appletOnLoad = function ( api ) {
				// One raw GeoGebra command per line; `evalCommand` executes
				// them in the applet (the GeoGebra DSL, never page JavaScript).
				commands.split( '\n' ).forEach( function ( line ) {
					var command = line.trim();
					if ( !command ) {
						return;
					}
					try {
						api.evalCommand( command );
					} catch ( e ) {
						fail( 'GeoGebra command failed: ' + command );
					}
				} );
			};
		}
		var applet = new GGBApplet( config, true );
		// Load the app from the SELF-HOSTED bundle, never the geogebra.org CDN
		// (deployggb.js defaults its codebase to the CDN; the CSP blocks it).
		applet.setHTML5Codebase( 'GeoGebra/HTML5/5.0/web3d/' );
		applet.inject( 'ggb-element' );
	};
	script.onerror = function () {
		fail( 'GeoGebra assets are not installed on this server.' );
	};
	document.head.appendChild( script );
}() );
