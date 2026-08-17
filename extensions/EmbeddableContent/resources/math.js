/*
 * Client-side KaTeX rendering for .wb-embed-math spans (issue #6 §4.2).
 * The KaTeX assets are vendored into resources/katex/ at deployment time
 * (see docs/); without them, the escaped LaTeX source is shown (no-JS path).
 */
( function () {
	'use strict';

	function renderMath() {
		var spans = document.querySelectorAll( '.wb-embed-math' );
		if ( !spans.length ) {
			return;
		}
		if ( !window.katex ) {
			return; // KaTeX not vendored — escaped source is shown instead
		}
		Array.prototype.forEach.call( spans, function ( span ) {
			var latex = span.getAttribute( 'data-latex' ) || '';
			if ( !latex ) {
				return;
			}
			// Strip one layer of $$…$$ / $…$ delimiters — katex.render()
			// expects bare TeX.
			latex = latex.replace( /^\$\$?/, '' ).replace( /\$\$?$/, '' );
			try {
				window.katex.render( latex, span, {
					throwOnError: false,
					displayMode: true
				} );
			} catch ( e ) {
				// keep the escaped source
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', renderMath );
	} else {
		renderMath();
	}
}() );
