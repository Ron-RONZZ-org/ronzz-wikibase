/*
 * Live KaTeX preview for Special:AddMath (issue follow-up): a Preview button
 * is injected above the form's submit button; clicking it renders the payload
 * into the preview box with the vendored KaTeX, after stripping one layer of
 * $…$ / $$…$$ / \(…\) / \[…\] delimiters (same normalization as the
 * server-side submit path, MathRenderer).
 */
( function () {
	'use strict';

	function stripDelimiters( input ) {
		var s = String( input || '' );
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
		var $input = $( '#mw-input-wppayload' );
		var $box = $( '#wb-math-preview-box' );
		var $content = $( '#wb-math-preview-content' );
		if ( !$input.length || !$box.length || !$content.length ) {
			return;
		}
		var $btn = $( '<button>' )
			.attr( 'type', 'button' )
			.attr( 'id', 'wb-math-preview' )
			.addClass( 'mw-ui-button mw-ui-progressive wb-math-preview-btn' )
			.text( mw.msg( 'embeddablecontent-add-math-preview' ) )
			.on( 'click', function () {
				var latex = stripDelimiters( $input.val() );
				try {
					if ( !window.katex ) {
						throw new Error( 'KaTeX not loaded' );
					}
					window.katex.render( latex, $content[ 0 ], {
						throwOnError: false,
						displayMode: true
					} );
				} catch ( e ) {
					// KaTeX absent or the TeX is invalid — show the stripped source.
					$content.text( latex );
				}
				$box.prop( 'hidden', false );
			} );
		// Preview button above the form's submit button (the module is loaded
		// only on the math page, so this never clashes with other forms).
		$( '.mw-htmlform-submit' ).first().before( $btn );
	} );
}() );
