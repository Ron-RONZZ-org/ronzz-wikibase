/*
 * Live KaTeX preview for Special:AddMath (issue follow-up): a Preview button
 * (native OOUI widget, matching the form's own buttons) is injected above the
 * submit button; clicking it renders the payload into the preview box with
 * the vendored KaTeX, after stripping one layer of $…$ / $$…$$ / \(…\) /
 * \[…\] delimiters (same normalization as the server-side submit path,
 * MathRenderer).
 *
 * The preview shows BOTH the Content (KaTeX-rendered) and the accompanying
 * Note. The Note is RICH WIKITEXT on the real page (links, [[File:…]],
 * emphasis, $…$ — the same wikitext the server appends in
 * ContentWikitext::math and RichTextRenderer parses), so the preview renders
 * it through the parse API and injects the resulting HTML. Inline $…$ is
 * then typeset with the same KaTeX (SimpleMathJax does not run inside this
 * preview), so the note's math previews too. If the parse call fails the
 * note degrades to plain text + inline KaTeX — never a blank preview.
 */
( function () {
	'use strict';

	// Latest-wins guard: a slow parse response must not overwrite the note
	// of a newer Preview click.
	var noteSeq = 0;

	function stripDelimiters( input ) {
		// Trim surrounding whitespace FIRST — the server
		// (MathRenderer::stripDelimiters) trims too, so a payload pasted with
		// a leading/trailing blank line is stripped identically here and at
		// submit time.
		var s = String( input || '' ).trim();
		var pairs = [
			[ /^\$\$([\s\S]*)\$\$$/, '$1' ],
			[ /^\$([\s\S]*)\$$/, '$1' ],
			[ /^\\\[([\s\S]*)\\\]$/, '$1' ],
			[ /^\\\(([\s\S]*)\\\)$/, '$1' ]
		];
		for ( var i = 0; i < pairs.length; i++ ) {
			var m = s.match( pairs[ i ][ 0 ] );
			if ( m ) {
				return m[ 1 ];
			}
		}
		return s;
	}

	/**
	 * Typesets every inline $…$ segment found in the TEXT NODES under root
	 * (the parsed wikitext may wrap the math in links/emphasis). Mirrors the
	 * SimpleMathJax inline delimiter.
	 */
	function typesetInlineMath( root ) {
		if ( !window.katex ) {
			return;
		}
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, null );
		var textNodes = [];
		var node;
		while ( ( node = walker.nextNode() ) ) {
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
					window.katex.render( m[ 1 ], span, { throwOnError: false, displayMode: false } );
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

	/**
	 * Fallback rendering (pre-parse-API behaviour): the plain text with line
	 * breaks kept and inline $…$ typeset with KaTeX. Used when the parse API
	 * is unavailable/fails.
	 */
	function renderNotePlain( el, text ) {
		el.textContent = '';
		text = String( text || '' );
		if ( !text.trim() ) {
			return;
		}
		var re = /\$([^$]+)\$/g;
		var last = 0;
		var m;
		while ( ( m = re.exec( text ) ) !== null ) {
			if ( m.index > last ) {
				el.appendChild( document.createTextNode( text.slice( last, m.index ) ) );
			}
			var span = document.createElement( 'span' );
			if ( window.katex ) {
				try {
					window.katex.render( m[ 1 ], span, { throwOnError: false, displayMode: false } );
				} catch ( e ) {
					span.textContent = m[ 1 ];
				}
			} else {
				span.textContent = m[ 1 ];
			}
			el.appendChild( span );
			last = m.index + m[ 0 ].length;
		}
		if ( last < text.length ) {
			el.appendChild( document.createTextNode( text.slice( last ) ) );
		}
	}

	/**
	 * Renders the note's wikitext into the preview slot through action=parse
	 * (the server is the wikitext renderer — links/media/emphasis/templates
	 * behave exactly as on the real page), then typesets the inline math.
	 * Falls back to renderNotePlain on any failure.
	 */
	function renderNote( el, text ) {
		text = String( text || '' );
		el.textContent = '';
		if ( !text.trim() ) {
			return;
		}
		var seq = ++noteSeq;
		var api = new mw.Api();
		api.get( {
			action: 'parse',
			contentmodel: 'wikitext',
			prop: 'text',
			disablelimitreport: 1,
			disableeditsection: 1,
			disabletoc: 1,
			text: text
		} ).done( function ( data ) {
			if ( seq !== noteSeq ) {
				return;
			}
			var html = data && data.parse && data.parse.text && data.parse.text[ '*' ];
			if ( typeof html !== 'string' ) {
				renderNotePlain( el, text );
				return;
			}
			var tmp = document.createElement( 'div' );
			tmp.innerHTML = html;
			typesetInlineMath( tmp );
			el.textContent = '';
			while ( tmp.firstChild ) {
				el.appendChild( tmp.firstChild );
			}
		} ).fail( function () {
			if ( seq === noteSeq ) {
				renderNotePlain( el, text );
			}
		} );
	}

	$( function () {
		// The payload is an OOUI textarea; the stable id (mw-input-wppayload)
		// sits on the WRAPPER widget div, the actual textarea/input inside it
		// carries a generated id (ooui-php-N) — select the inner control so
		// .val() reads what the user typed.
		var $input = $( '#mw-input-wppayload' ).find( 'textarea, input' ).first();
		var $noteInput = $( '#mw-input-wpnote' ).find( 'textarea, input' ).first();
		var $box = $( '#wb-math-preview-box' );
		var $content = $( '#wb-math-preview-content' );
		var $noteWrap = $( '#wb-math-preview-note-wrap' );
		var $note = $( '#wb-math-preview-note' );
		if ( !$input.length || !$box.length || !$content.length ) {
			return;
		}
		var btn = new OO.ui.ButtonWidget( {
			id: 'wb-math-preview',
			label: mw.msg( 'embeddablecontent-add-math-preview' ),
			flags: [ 'progressive' ],
			classes: [ 'wb-math-preview-btn' ]
		} );
		btn.on( 'click', function () {
			var latex = stripDelimiters( $input.val() );
			$box.removeClass( 'wb-math-preview-error' );
			try {
				if ( !window.katex ) {
					throw new Error( 'KaTeX is not available on this page.' );
				}
				// throwOnError:true so a malformed expression raises here
				// instead of rendering KaTeX's inline red span — the catch
				// shows the renderer's own precise message as text.
				window.katex.render( latex, $content[ 0 ], {
					throwOnError: true,
					displayMode: true
				} );
			} catch ( e ) {
				// Show the TeX renderer's error message (e.g. "KaTeX parse
				// error: Undefined control sequence: \foo").
				$content.text( e.message );
				$box.addClass( 'wb-math-preview-error' );
			}
			// The accompanying note, shown below the expression when present.
			// The wrapper is revealed immediately (the parsed HTML arrives
			// asynchronously).
			if ( $note.length ) {
				var noteText = $noteInput.length ? String( $noteInput.val() || '' ) : '';
				if ( noteText.trim() !== '' ) {
					$noteWrap.prop( 'hidden', false );
					renderNote( $note[ 0 ], noteText );
				} else {
					$note.text( '' );
					$noteWrap.prop( 'hidden', true );
				}
			}
			$box.prop( 'hidden', false );
		} );
		// Preview button above the form's submit button (the module is loaded
		// only on the math page, so this never clashes with other forms).
		$( '.mw-htmlform-submit' ).first().before( btn.$element );
	} );
}() );
