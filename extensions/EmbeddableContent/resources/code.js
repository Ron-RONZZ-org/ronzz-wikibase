/*
 * Client-side syntax highlighting for .wb-embed-code <pre> elements
 * (issue follow-up). highlight.js is loaded via the embed module; the lexer
 * comes from the data-lexer attribute (Pygments-style lexer names, mapped to
 * highlight.js aliases; unknown names fall back to plain text).
 */
( function () {
	'use strict';

	// highlight.js is loaded as a ResourceLoader module — its UMD detects
	// the CommonJS wrapper and exports via module.exports instead of setting
	// window.hljs, so require it explicitly.
	var hljsLib = null;
	try {
		hljsLib = require( 'ext.embeddableContent.highlight' );
	} catch ( e ) {
		hljsLib = window.hljs || null;
	}

	// Pygments-style names → highlight.js aliases.
	var LEXER_ALIASES = {
		'c++': 'cpp',
		'c#': 'csharp',
		'csharp': 'csharp',
		'f#': 'fsharp',
		'sh': 'bash',
		'shell': 'bash',
		'zsh': 'bash',
		'py': 'python',
		'js': 'javascript',
		'node': 'javascript',
		'html': 'xml',
		'xhtml': 'xml',
		'text': 'plaintext',
		'none': 'plaintext',
		'console': 'plaintext'
	};

	function highlightCode() {
		var blocks = document.querySelectorAll( '.wb-embed-code[data-lexer]' );
		if ( !blocks.length || !hljsLib ) {
			return; // highlight.js not loaded — escaped source is shown
		}
		Array.prototype.forEach.call( blocks, function ( pre ) {
			var lexer = ( pre.getAttribute( 'data-lexer' ) || 'text' ).toLowerCase();
			var alias = LEXER_ALIASES[ lexer ] || lexer;
			try {
				if ( hljsLib.getLanguage( alias ) ) {
					pre.classList.add( 'hljs' );
					hljsLib.highlightElement( pre );
				} else {
					pre.classList.add( 'hljs-plaintext' );
				}
			} catch ( e ) {
				// keep the escaped source
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', highlightCode );
	} else {
		highlightCode();
	}
}() );
