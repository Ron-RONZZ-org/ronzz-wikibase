/**
 * Userspace subpage H1: "Pretty good Linux by @Rongzhou".
 *
 * The server already sets the page title to the translated "<subpage> by
 * @<user>" text (Hooks::applyUserPageTitle), so this module is a progressive
 * enhancement: it rebuilds the H1 with a real link to the author's user page
 * (the page-title sanitizer does not allow <a>). Without JavaScript the H1
 * keeps the plain "@Rongzhou" text.
 *
 * Config: wbUserPageTitle = { title, by, user, url, exists }.
 */
( function () {
	'use strict';

	function mount() {
		var cfg = mw.config.get( 'wbUserPageTitle' );
		var heading = document.getElementById( 'firstHeading' );
		if ( !cfg || !heading ) {
			return;
		}
		// The skin wraps the title in <span lang dir> (namespace titles carry
		// the namespace span inside it); keep that wrapper and replace its
		// contents.
		var wrapper = heading.querySelector( 'span[lang]' ) || heading;

		var title = document.createElement( 'span' );
		title.className = 'mw-page-title-main';
		title.textContent = cfg.title;

		var by = document.createElement( 'span' );
		by.className = 'wb-userpage-by';
		by.appendChild( document.createTextNode( cfg.by + ' ' ) );

		var author = document.createElement( 'a' );
		author.href = cfg.url;
		author.textContent = '@' + cfg.user;
		if ( cfg.exists === false ) {
			// Match MediaWiki's red-link convention for a missing User: page.
			author.className = 'new';
		}
		by.appendChild( author );

		wrapper.textContent = '';
		wrapper.appendChild( title );
		wrapper.appendChild( document.createTextNode( ' ' ) );
		wrapper.appendChild( by );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
}() );
