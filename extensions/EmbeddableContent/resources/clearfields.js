/**
 * Per-field "clear" button + indefinite undo on the Special:Update* forms.
 *
 * The Update* form is prefilled from the item, so clearing a field is how a
 * contributor removes a statement. This module adds a small × to every
 * editable value field. Clicking it blanks the field (and the OSM label
 * sibling); the button then becomes an indefinite ↶ undo. Undo always
 * restores the value the field had when the FORM WAS OPENED — not the value
 * just before the clear. If the field now holds different content, a
 * confirmation warns that undo discards those changes.
 *
 * The server side (Spec\StatementSnapshot) decides whether the blank actually
 * removes a statement: only when the item is unchanged since the form opened.
 *
 * Not wired: checkboxes/radios (their own toggle is the clear), file inputs,
 * hidden bookkeeping fields (image/access lists, edit token).
 */
( function () {
	'use strict';

	var SKIP_TYPES = {
		hidden: true, checkbox: true, radio: true, file: true,
		submit: true, button: true, reset: true, image: true
	};
	var SKIP_NAMES = {
		wpEditToken: true,
		wpimageEntries: true,
		wpaccessEntries: true,
		wpclass: true
	};

	function clearLabel() {
		return mw.msg( 'embeddablecontent-clear-field' );
	}

	function undoLabel() {
		return mw.msg( 'embeddablecontent-undo-field' );
	}

	/** The human-readable label sibling of an OSM combobox (or null). */
	function labelSibling( input ) {
		var name = input.getAttribute( 'name' ) || '';
		if ( name === '' ) {
			return null;
		}
		return document.querySelector( 'input[name="' + name + 'Label"]' );
	}

	function readState( input ) {
		var label = labelSibling( input );
		return {
			value: input.value,
			label: label ? label.value : null
		};
	}

	/** Set a field's value through its OOUI widget when possible. */
	function writeState( input, state ) {
		var wrapper = input.closest ? input.closest( '[data-ooui]' ) : null;
		var applied = false;
		if ( wrapper && window.OO && OO.ui && OO.ui.Widget && OO.ui.Widget.static ) {
			try {
				var widget = OO.ui.Widget.static.infuse( wrapper );
				if ( widget && typeof widget.setValue === 'function' ) {
					widget.setValue( state.value );
					applied = true;
				}
			} catch ( e ) {
				// Not infusable (plain PHP input) — fall through.
			}
		}
		if ( !applied ) {
			input.value = state.value;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		var label = labelSibling( input );
		if ( label && state.label !== null ) {
			label.value = state.label;
			label.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			label.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	function isEditableValue( input ) {
		if ( input.tagName !== 'INPUT' && input.tagName !== 'TEXTAREA' ) {
			return false;
		}
		if ( input.tagName === 'INPUT' ) {
			var type = ( input.getAttribute( 'type' ) || 'text' ).toLowerCase();
			if ( SKIP_TYPES[ type ] ) {
				return false;
			}
		}
		var name = input.getAttribute( 'name' ) || '';
		if ( !/^wp[A-Za-z0-9_]+$/.test( name ) || SKIP_NAMES[ name ] ) {
			return false;
		}
		if ( input.disabled || input.readOnly ) {
			return false;
		}
		return !!( input.closest && input.closest( '.oo-ui-fieldLayout' ) );
	}

	function applyButtonState( button, cleared ) {
		if ( cleared ) {
			button.textContent = '↶';
			button.setAttribute( 'title', undoLabel() );
			button.setAttribute( 'aria-label', undoLabel() );
			button.classList.add( 'wb-clear-field-undo' );
		} else {
			button.textContent = '×';
			button.setAttribute( 'title', clearLabel() );
			button.setAttribute( 'aria-label', clearLabel() );
			button.classList.remove( 'wb-clear-field-undo' );
		}
	}

	function wire( input ) {
		var original = readState( input );
		var cleared = false;
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'wb-clear-field';
		applyButtonState( button, false );

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			if ( !cleared ) {
				writeState( input, { value: '', label: '' } );
				cleared = true;
				applyButtonState( button, true );
				return;
			}
			var current = readState( input );
			var hasNewContent = current.value !== ''
				|| ( current.label !== null && current.label !== '' );
			var restore = function () {
				writeState( input, original );
				cleared = false;
				applyButtonState( button, false );
			};
			if ( !hasNewContent ) {
				restore();
				return;
			}
			OO.ui.confirm( mw.msg( 'embeddablecontent-undo-field-warning' ) )
				.then( function ( confirmed ) {
					if ( confirmed ) {
						restore();
					}
				} );
		} );

		var fieldLayout = input.closest( '.oo-ui-fieldLayout-field' );
		if ( fieldLayout ) {
			fieldLayout.classList.add( 'wb-has-clear' );
			// Combobox/date widgets carry their own indicator at the right;
			// keep the × clear of it.
			if ( input.closest( '.oo-ui-comboBoxInputWidget, .oo-ui-dateInputWidget' ) ) {
				fieldLayout.classList.add( 'wb-has-clear-indicator' );
			}
			fieldLayout.appendChild( button );
		} else if ( input.parentNode ) {
			input.parentNode.insertBefore( button, input.nextSibling );
		}
	}

	mw.loader.using( [ 'oojs-ui' ] ).then( function () {
		var form = document.querySelector( '#mw-content-text form' );
		if ( !form ) {
			return;
		}
		var wireAll = function () {
			form.querySelectorAll( 'input, textarea' ).forEach( function ( input ) {
				if ( isEditableValue( input ) && !input.dataset.wbClearWired ) {
					input.dataset.wbClearWired = '1';
					wire( input );
				}
			} );
		};
		wireAll();
		// Cloner rows (quotation translations, additional statements) and the
		// author panel add inputs after page load — keep them clearable too.
		new MutationObserver( wireAll ).observe( form, {
			childList: true,
			subtree: true
		} );
	} );
}() );
