/**
 * Shared inline-`$…$` KaTeX typesetter for rich text rendered OUTSIDE the
 * normal page parse — the AddMath live preview and the Item-page content
 * preview (where SimpleMathJax does not run, because the fragment is
 * injected after load). Exposes:
 *
 *   mw.embeddableContent.typesetInlineMath( root, options )
 *
 * `root` is an Element/DocumentFragment; every text node containing `$…$` is
 * replaced by KaTeX-rendered spans. Already-typeset nodes (inside `.katex`
 * or `mjx-container`) are skipped, so re-running is idempotent.
 */
( function () {
	'use strict';

	function typesetInlineMath( root, options ) {
		if ( !root || !window.katex ) {
			return;
		}
		var display = !!( options && options.displayMode );
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, null );
		var textNodes = [];
		var node;
		while ( ( node = walker.nextNode() ) ) {
			var parent = node.parentNode;
			if ( parent && parent.closest && parent.closest( '.katex, mjx-container' ) ) {
				continue;
			}
			if ( node.nodeValue && node.nodeValue.indexOf( '$' ) !== -1 ) {
				textNodes.push( node );
			}
		}
		textNodes.forEach( function ( textNode ) {
			var text = textNode.nodeValue;
			var re = /\$([^$]+)\$/g;
			var last = 0;
			var m;
			var frag = document.createDocumentFragment();
			while ( ( m = re.exec( text ) ) !== null ) {
				if ( m.index > last ) {
					frag.appendChild( document.createTextNode( text.slice( last, m.index ) ) );
				}
				var span = document.createElement( 'span' );
				try {
					window.katex.render( m[ 1 ], span, { throwOnError: false, displayMode: display } );
				} catch ( e ) {
					span.textContent = m[ 1 ];
				}
				frag.appendChild( span );
				last = m.index + m[ 0 ].length;
			}
			if ( last === 0 ) {
				return;
			}
			if ( last < text.length ) {
				frag.appendChild( document.createTextNode( text.slice( last ) ) );
			}
			textNode.parentNode.replaceChild( frag, textNode );
		} );
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.typesetInlineMath = typesetInlineMath;
}() );
