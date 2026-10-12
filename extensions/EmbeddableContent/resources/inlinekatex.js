/**
 * Shared math typesetter for rich text rendered OUTSIDE the normal page
 * parse — the AddMath live preview and the Item-page content preview (where
 * SimpleMathJax does not run, because the fragment is injected after load).
 * Exposes:
 *
 *   mw.embeddableContent.typesetInlineMath( root, options )
 *
 * `root` is an Element/DocumentFragment; every text node containing math
 * delimiters is replaced by KaTeX-rendered spans. Already-typeset nodes
 * (inside `.katex` or `mjx-container`) are skipped, so re-running is
 * idempotent.
 *
 * Delimiters are matched LONGEST-FIRST, mirroring the instance's
 * SimpleMathJax configuration (`$wgSmjDisplayMath = '$$'`, inline `$`, plus
 * the LaTeX `\[…\]` / `\(…\)` forms) and the vendored extension's own
 * `SimpleMathJaxQuotes` search: `$$…$$` (and `\[…\]`) render as DISPLAY
 * math, `$…$` (and `\(…\)`) as inline. A single-`$` matcher alone mis-reads
 * `$$ b = c $$` as the INNER `$ b = c $` plus two stray `$` literals (the
 * reported note-preview bug); the longest-first scan terminates the display
 * span correctly. An unbalanced opener is skipped whole (MathJax ignores it),
 * never paired with a later delimiter.
 *
 * `options.displayMode` (when set) additionally forces the inline
 * delimiters to display mode; it never demotes a display delimiter.
 */
( function () {
	'use strict';

	// Longest-first: `$$` before `$` (both start with `$`), `\[` and `\(`
	// after (they start with `\`). The scan tries each at the position.
	var DELIMITERS = [
		{ open: '$$', close: '$$', display: true },
		{ open: '$', close: '$', display: false },
		{ open: '\\[', close: '\\]', display: true },
		{ open: '\\(', close: '\\)', display: false }
	];

	function makeMathSpan( content, display ) {
		var span = document.createElement( 'span' );
		try {
			window.katex.render( content, span, { throwOnError: false, displayMode: display } );
		} catch ( e ) {
			span.textContent = content;
		}
		return span;
	}

	/**
	 * Replaces the math delimiters in one text node with KaTeX spans. A
	 * no-op when the text carries no delimiter.
	 */
	function typesetTextNode( textNode, defaultDisplay ) {
		var text = textNode.nodeValue;
		if ( !text || ( text.indexOf( '$' ) === -1 && text.indexOf( '\\' ) === -1 ) ) {
			return;
		}

		var frag = document.createDocumentFragment();
		var last = 0;
		var i = 0;
		var matched = false;

		while ( i < text.length ) {
			var ch = text.charAt( i );
			if ( ch !== '$' && ch !== '\\' ) {
				i++;
				continue;
			}

			var advanced = false;
			for ( var d = 0; d < DELIMITERS.length; d++ ) {
				var delim = DELIMITERS[ d ];
				if ( text.substr( i, delim.open.length ) !== delim.open ) {
					continue;
				}
				var end = text.indexOf( delim.close, i + delim.open.length );
				if ( end === -1 ) {
					// Unbalanced opener — skip the whole opener, never pair
					// it with a later delimiter (MathJax ignores it).
					i += delim.open.length;
					advanced = true;
					break;
				}
				if ( i > last ) {
					frag.appendChild( document.createTextNode( text.slice( last, i ) ) );
				}
				frag.appendChild( makeMathSpan(
					text.slice( i + delim.open.length, end ),
					delim.display || defaultDisplay
				) );
				i = end + delim.close.length;
				last = i;
				matched = true;
				advanced = true;
				break;
			}
			if ( !advanced ) {
				i++;
			}
		}

		if ( !matched ) {
			return;
		}
		if ( last < text.length ) {
			frag.appendChild( document.createTextNode( text.slice( last ) ) );
		}
		textNode.parentNode.replaceChild( frag, textNode );
	}

	function typesetInlineMath( root, options ) {
		if ( !root || !window.katex ) {
			return;
		}
		var defaultDisplay = !!( options && options.displayMode );
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, null );
		var textNodes = [];
		var node;
		while ( ( node = walker.nextNode() ) ) {
			var parent = node.parentNode;
			if ( parent && parent.closest && parent.closest( '.katex, mjx-container' ) ) {
				continue;
			}
			if ( node.nodeValue && ( node.nodeValue.indexOf( '$' ) !== -1 || node.nodeValue.indexOf( '\\' ) !== -1 ) ) {
				textNodes.push( node );
			}
		}
		textNodes.forEach( function ( textNode ) {
			typesetTextNode( textNode, defaultDisplay );
		} );
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.typesetInlineMath = typesetInlineMath;
}() );
