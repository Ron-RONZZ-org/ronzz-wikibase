/**
 * Shared "Copy citation" popup. Opened by the entity/classic-page toolbar
 * (gadget.js) and by the ordinary-content-page toolbar
 * (contentpagetoolbar.js). It renders, anchored to the clicked button:
 *
 *   - a format dropdown (APA default, Vancouver, BibTeX, RIS),
 *   - a live preview of the formatted citation for the chosen format,
 *   - a copy button.
 *
 * The citation target is either an ITEM (`{ entity: 'Q42' }` — entity pages,
 * classic per-kind pages, the Add* success popup) or a WIKI PAGE
 * (`{ page: 'Orthonormality' }` — ordinary content pages, cited as the page
 * itself with its last-revision date). Both go through api.php?action=citation.
 *
 * Exposes: mw.embeddableContent.citationPopup.open( $anchor, target ).
 */
( function () {
	'use strict';

	var STYLES = [
		{ key: 'apa', label: 'APA' },
		{ key: 'vancouver', label: 'Vancouver' },
		{ key: 'bibtex', label: 'BibTeX' },
		{ key: 'ris', label: 'RIS' }
	];

	var activePopup = null;

	function closeActive() {
		if ( activePopup ) {
			activePopup.$element.remove();
			activePopup = null;
		}
	}

	/** Copy `text`; notifies on success. */
	function copyText( text ) {
		var done = function () {
			mw.notify( mw.msg( 'embeddablecontent-gadget-copied' ) );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text ).then( done, function () {
				fallbackCopy( text );
				done();
			} );
		}
		fallbackCopy( text );
		done();
	}

	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try {
			document.execCommand( 'copy' );
		} finally {
			document.body.removeChild( ta );
		}
	}

	function targetParams( target, style ) {
		var params = { action: 'citation', output: 'text', style: style };
		if ( target.entity ) {
			params.entity = target.entity;
		} else if ( target.page ) {
			params.page = target.page;
		}
		return params;
	}

	function open( $anchor, target ) {
		closeActive();

		var api = new mw.Api();
		var seq = 0;
		var $preview = $( '<div class="wb-citation-popup-preview"></div>' );

		var dropdown = new OO.ui.DropdownInputWidget( {
			options: STYLES.map( function ( style ) {
				return { data: style.key, label: style.label };
			} ),
			value: 'apa'
		} );
		var styleField = new OO.ui.FieldLayout( dropdown, {
			label: mw.msg( 'embeddablecontent-gadget-citation-style' ),
			align: 'top',
			classes: [ 'wb-citation-popup-style' ]
		} );
		var copyButton = new OO.ui.ButtonWidget( {
			label: mw.msg( 'embeddablecontent-citationpopup-copy' ),
			flags: [ 'primary', 'progressive' ]
		} );

		function fetch( style ) {
			var current = ++seq;
			$preview.removeClass( 'wb-citation-popup-error' );
			$preview.text( mw.msg( 'embeddablecontent-citationpopup-loading' ) );
			api.get( targetParams( target, style ) ).done( function ( data ) {
				if ( current !== seq ) {
					return;
				}
				var text = ( data && data.citation && !data.error ) ? data.citation : '';
				if ( text === '' ) {
					$preview.addClass( 'wb-citation-popup-error' )
						.text( mw.msg( 'embeddablecontent-citationpopup-error' ) );
				} else {
					$preview.text( text );
				}
			} ).fail( function () {
				if ( current !== seq ) {
					return;
				}
				$preview.addClass( 'wb-citation-popup-error' )
					.text( mw.msg( 'embeddablecontent-citationpopup-error' ) );
			} );
		}

		dropdown.on( 'change', function () {
			fetch( dropdown.getValue() );
		} );
		copyButton.on( 'click', function () {
			if ( !$preview.hasClass( 'wb-citation-popup-error' ) ) {
				copyText( $preview.text() );
			}
		} );

		var $content = $( '<div class="wb-citation-popup"></div>' )
			.append( styleField.$element )
			.append( $preview )
			.append( $( '<div class="wb-citation-popup-actions"></div>' ).append( copyButton.$element ) );

		var popup = new OO.ui.PopupWidget( {
			$content: $content,
			$floatableContainer: $anchor,
			padded: true,
			autoFlip: true,
			head: true,
			// Raised above an OOUI modal dialog (the Add* "Item added"
			// popup is z-index 450; a PopupWidget defaults to 1, so the
			// citation popup used to hide behind it) — see gadget.css.
			classes: [ 'wb-citation-popup-widget' ],
			label: mw.msg( 'embeddablecontent-gadget-copycitation' )
		} );
		popup.on( 'toggle', function ( visible ) {
			if ( !visible ) {
				popup.$element.remove();
				if ( activePopup === popup ) {
					activePopup = null;
				}
			}
		} );
		$( document.body ).append( popup.$element );
		activePopup = popup;
		popup.toggle( true );
		fetch( 'apa' );
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.citationPopup = { open: open };
}() );
