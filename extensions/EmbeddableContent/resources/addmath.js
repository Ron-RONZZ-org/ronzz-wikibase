/*
 * Live KaTeX preview for Special:AddMath (issue follow-up): a Preview button
 * (native OOUI widget, matching the form's own buttons) is injected above the
 * submit button; clicking it renders the payload into the preview box with
 * the vendored KaTeX, after stripping one layer of $…$ / $$…$$ / \(…\) /
 * \[…\] delimiters (same normalization as the server-side submit path,
 * MathRenderer).
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

	$( function () {
		// The payload is an OOUI textarea; the stable id (mw-input-wppayload)
		// sits on the WRAPPER widget div, the actual textarea/input inside it
		// carries a generated id (ooui-php-N) — select the inner control so
		// .val() reads what the user typed.
		var $input = $( '#mw-input-wppayload' ).find( 'textarea, input' ).first();
		var $box = $( '#wb-math-preview-box' );
		var $content = $( '#wb-math-preview-content' );
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
			$box.prop( 'hidden', false );
		} );
		// Preview button above the form's submit button (the module is loaded
		// only on the math page, so this never clashes with other forms).
		$( '.mw-htmlform-submit' ).first().before( btn.$element );
	} );
}() );
