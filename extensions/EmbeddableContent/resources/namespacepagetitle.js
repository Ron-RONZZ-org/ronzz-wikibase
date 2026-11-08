/**
 * Non-main content-page H1: turn the namespace name into a link to its
 * alphabetical index.
 *
 * The server already renders "Beloved <span class="wb-namespace-in">in</span>
 * <span class="wb-namespace-name">Source</span>" (Hooks::applyDisplayTitle),
 * so the muted connector works without JavaScript. The page-title sanitizer
 * does not allow <a> in the H1, hence this progressive enhancement: without
 * JavaScript the heading keeps the plain namespace name.
 *
 * Config: wbNamespacePageTitle = { url }.
 */
( function () {
	'use strict';

	function mount() {
		var cfg = mw.config.get( 'wbNamespacePageTitle' );
		var heading = document.getElementById( 'firstHeading' );
		if ( !cfg || !cfg.url || !heading ) {
			return;
		}
		var name = heading.querySelector( '.wb-namespace-name' );
		if ( !name || name.querySelector( 'a' ) ) {
			return;
		}
		var link = document.createElement( 'a' );
		link.href = cfg.url;
		link.textContent = name.textContent;
		name.textContent = '';
		name.appendChild( link );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
}() );
