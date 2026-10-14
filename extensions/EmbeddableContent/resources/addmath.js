/*
 * Live KaTeX preview for Special:AddMath (issue follow-up): a Preview button
 * (native OOUI widget, matching the form's own buttons) is injected above the
 * submit button; clicking it renders the payload into the preview box with
 * the vendored KaTeX, after stripping one layer of $…$ / $$…$$ / \(…\) /
 * \[…\] delimiters (same normalization as the server-side submit path,
 * MathRenderer).
 *
 * The preview shows the PRE-content Note (rendered ABOVE the expression), the
 * Content (KaTeX-rendered), then the POST-content Note (rendered BELOW). Both
 * Notes are RICH WIKITEXT on the real page (links, [[File:…]], emphasis, $…$ —
 * the same wikitext the server appends in ContentWikitext::math and
 * RichTextRenderer parses), so the preview renders them through the parse API
 * and injects the resulting HTML. Inline $…$ is then typeset with the same
 * KaTeX (SimpleMathJax does not run inside this preview), so each note's math
 * previews too. If the parse call fails a note degrades to plain text + inline
 * KaTeX — never a blank preview.
 */
( function () {
	'use strict';

	// Latest-wins guard: a slow parse response must not overwrite a newer
	// Preview click's note. The counter is PER SLOT (stored on the target
	// element) so the pre-content note and the post-content note can each be
	// in flight without cancelling the other.
	function nextNoteSeq( el ) {
		var seq = ( el.wbNoteSeq || 0 ) + 1;
		el.wbNoteSeq = seq;
		return seq;
	}

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

	/*
	 * The inline-`$…$` typesetter is shared with the Item-page preview
	 * (resources/inlinekatex.js, ext.embeddableContent.inlinekatex): it is
	 * called as mw.embeddableContent.typesetInlineMath( root ).
	 */

	/**
	 * Fallback rendering (pre-parse-API behaviour): the plain text with line
	 * breaks kept and the math delimiters typeset with KaTeX through the
	 * SHARED typesetter (ext.embeddableContent.inlinekatex), so `$$…$$` /
	 * `$…$` / `\[…\]` / `\(…\)` pair exactly as in the parsed path. Used when
	 * the parse API is unavailable/fails.
	 */
	function renderNotePlain( el, text ) {
		el.textContent = String( text || '' );
		if ( mw.embeddableContent && mw.embeddableContent.typesetInlineMath ) {
			mw.embeddableContent.typesetInlineMath( el );
		}
	}

	/**
	 * Renders one note slot (a note may be the pre-content or the post-content
	 * note). The wrapper is revealed when the note is non-empty; an empty note
	 * hides the wrapper. The parsed HTML arrives asynchronously.
	 */
	function renderNoteBlock( $wrap, $el, $input ) {
		if ( !$el.length ) {
			return;
		}
		var text = $input.length ? String( $input.val() || '' ) : '';
		if ( text.trim() !== '' ) {
			$wrap.prop( 'hidden', false );
			renderNote( $el[ 0 ], text );
		} else {
			$el.text( '' );
			$wrap.prop( 'hidden', true );
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
		var seq = nextNoteSeq( el );
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
			if ( el.wbNoteSeq !== seq ) {
				return;
			}
			var html = data && data.parse && data.parse.text && data.parse.text[ '*' ];
			if ( typeof html !== 'string' ) {
				renderNotePlain( el, text );
				return;
			}
			var tmp = document.createElement( 'div' );
			tmp.innerHTML = html;
			mw.embeddableContent.typesetInlineMath( tmp );
			el.textContent = '';
			while ( tmp.firstChild ) {
				el.appendChild( tmp.firstChild );
			}
		} ).fail( function () {
			if ( el.wbNoteSeq === seq ) {
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
		var $preNoteInput = $( '#mw-input-wppreNote' ).find( 'textarea, input' ).first();
		var $box = $( '#wb-math-preview-box' );
		var $content = $( '#wb-math-preview-content' );
		var $noteWrap = $( '#wb-math-preview-note-wrap' );
		var $note = $( '#wb-math-preview-note' );
		var $preNoteWrap = $( '#wb-math-preview-prenote-wrap' );
		var $preNote = $( '#wb-math-preview-prenote' );
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
			// The pre-content note (above the expression) and the post-content
			// note (below it), each shown only when present. The wrappers are
			// revealed immediately (the parsed HTML arrives asynchronously).
			renderNoteBlock( $preNoteWrap, $preNote, $preNoteInput );
			renderNoteBlock( $noteWrap, $note, $noteInput );
			$box.prop( 'hidden', false );
		} );
		// Preview button above the form's submit button (the module is loaded
		// only on the math page, so this never clashes with other forms).
		$( '.mw-htmlform-submit' ).first().before( btn.$element );
	} );
}() );
