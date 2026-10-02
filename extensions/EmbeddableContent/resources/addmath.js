/*
 * Live KaTeX preview for Special:AddMath (issue follow-up): a Preview button
 * (native OOUI widget, matching the form's own buttons) is injected above the
 * submit button; clicking it renders the payload into the preview box with
 * the vendored KaTeX, after stripping one layer of $…$ / $$…$$ / \(…\) /
 * \[…\] delimiters (same normalization as the server-side submit path,
 * MathRenderer).
 *
 * The preview shows BOTH the Content (KaTeX-rendered) and the accompanying
 * Note (plain wikitext — inline $…$ is typeset with the same KaTeX so the
 * note's math previews too).
 */
( function () {
	'use strict';

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
	 * Renders the note's wikitext into the preview slot: the plain text is
	 * kept (line breaks included — the slot uses white-space: pre-wrap) and
	 * inline $…$ segments are typeset with KaTeX. Links/media are not
	 * previewed (they render on the real page); the math is.
	 */
	function renderNote( el, text ) {
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
			if ( $note.length ) {
				renderNote( $note[ 0 ], $noteInput.length ? $noteInput.val() : '' );
				$noteWrap.prop( 'hidden', $note.children().length === 0 );
			}
			$box.prop( 'hidden', false );
		} );
		// Preview button above the form's submit button (the module is loaded
		// only on the math page, so this never clashes with other forms).
		$( '.mw-htmlform-submit' ).first().before( btn.$element );
	} );
}() );
