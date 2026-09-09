/**
 * AddSource live label preview — the class-disambiguation suffix shown in
 * a second, read-only field to the right of the Title field.
 *
 * The stored item label is the title WITH the English class suffix ("The
 * Hobbit (Book)", "A chapter (Book excerpt)") — the server appends it at
 * creation (SourceFlowService::labelFor) and prefills the review default.
 * While typing in the manual/review form, that concatenation is invisible
 * until submit; this module mirrors it live: as the user edits the Title
 * field, the read-only preview to its right shows exactly what the item
 * label will be — the typed title plus the " (Class)" suffix, appended
 * idempotently (a value that already ends with the suffix is kept as-is,
 * matching the server's disambiguatedTitle rule). No value → empty
 * preview.
 *
 * The suffix text is server-provided (mw.config wbLabelSuffix — the
 * English class label in parentheses, e.g. " (Book)"); it is only set on
 * class-scoped AddSource review/manual steps where the suffix applies
 * (never Special:UpdateSource, which keeps stored labels as-is).
 *
 * ⚠️ DOM robustness: the OOUI HTMLForm re-creates autoinfuse field
 * layouts client-side from their data-ooui config, so the server-rendered
 * Title widget (and anything appended next to it) can be replaced after
 * module load. The input event listener is therefore DELEGATED on
 * document (survives widget replacement), and a MutationObserver
 * re-creates the preview when it has been wiped.
 */
( function () {
	'use strict';

	var suffix = mw.config.get( 'wbLabelSuffix' ) || '';
	var wired = false;

	function applySuffix( value ) {
		var trimmed = String( value || '' ).trim();
		if ( !trimmed || !suffix ) {
			return trimmed;
		}
		var needle = suffix.toLowerCase();
		if ( trimmed.toLowerCase().endsWith( needle ) ) {
			return trimmed;
		}
		return trimmed + suffix;
	}

	/**
	 * (Re-)creates the preview next to the Title field's layout. The field
	 * cell becomes a flex line (widget + preview); OOUI's label-above-input
	 * arrangement stays intact.
	 */
	function wire() {
		var $input = $( 'input[name="wptitle"]' ).first();
		if ( !$input.length || !suffix ) {
			return;
		}
		var $field = $input.closest( '.oo-ui-fieldLayout' ).first();
		if ( $field.length === 0 ) {
			return;
		}
		if ( $field.find( '.wb-label-preview' ).length ) {
			return; // idempotent (hide-if re-insertion re-runs wire())
		}

		$field.addClass( 'wb-addsource-title-row' );

		var $preview = $( '<span class="wb-label-preview"></span>' )
			.attr( 'title', mw.msg( 'embeddablecontent-addsource-label-preview-hint' ) );
		var $text = $( '<span class="wb-label-preview-text"></span>' );
		$preview.append(
			$( '<span class="wb-label-preview-label"></span>' )
				.text( mw.msg( 'embeddablecontent-addsource-label-preview' ) ),
			$text
		);
		$field.find( '.oo-ui-fieldLayout-field' ).first().append( $preview );

		var sync = function () {
			$text.text( applySuffix( $( 'input[name="wptitle"]' ).first().val() ) );
		};
		// Delegated: the widget may be replaced by OOUI infusion later.
		if ( !wired ) {
			wired = true;
			$( document ).on( 'input change', 'input[name="wptitle"]', sync );
		}
		sync();
	}

	$( function () {
		wire();
		if ( typeof MutationObserver !== 'undefined' ) {
			new MutationObserver( function () {
				if ( $( 'input[name="wptitle"]' ).length && $( '.wb-label-preview' ).length === 0 ) {
					wire();
				}
			} ).observe( document.body, { childList: true, subtree: true } );
		}
	} );
}() );
