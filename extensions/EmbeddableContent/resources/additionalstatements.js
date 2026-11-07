/**
 * Additional semantic statements — property and value comboboxes.
 *
 * The cloner's Property field is a server-rendered combobox over the
 * property catalog (P-id + label). The Value field is a combobox too: for a
 * wikibase-item property it is switched to the shared entity-search
 * combobox (action=entitysearch); for the literal datatypes (string,
 * external-id, url, quantity, time) it stays a free-text combobox whose
 * placeholder shows the expected shape.
 *
 * The cloner adds rows dynamically, so a debounced MutationObserver wires
 * new rows as they appear. Prefilled Update* rows are wired on load.
 */
( function () {
	'use strict';

	var PLACEHOLDERS = {
		'wikibase-item': 'Q123',
		string: 'Text',
		'external-id': 'ID',
		url: 'https://…',
		quantity: '42',
		time: 'YYYY-MM-DD'
	};

	mw.loader.using( [ 'oojs-ui', 'ext.embeddableContent.entitysuggest' ] ).then( function () {
		var datatypes = mw.config.get( 'wbAdditionalStatementDatatypes' ) || {};

		function closestRow( $el ) {
			var $row = $el.closest( 'li.mw-htmlform-cloner-li' );
			if ( $row.length ) {
				return $row;
			}
			return $el.closest( 'div' );
		}

		function wireRow( $row ) {
			if ( !$row.length || $row.data( 'wbAdditionalWired' ) ) {
				return;
			}
			var $property = $row.find( '.wb-additional-property.oo-ui-comboBoxInputWidget' ).first();
			var $value = $row.find( '.wb-additional-value.oo-ui-comboBoxInputWidget' ).first();
			if ( !$property.length || !$value.length ) {
				return;
			}
			$row.data( 'wbAdditionalWired', true );
			var propertyCombo = OO.ui.ComboBoxInputWidget.static.infuse( $property );
			var valueCombo = OO.ui.ComboBoxInputWidget.static.infuse( $value );

			function updateValue() {
				var pid = String( propertyCombo.getValue() || '' ).trim().toUpperCase();
				var datatype = datatypes[ pid ] || '';
				var $input = valueCombo.$input;
				if ( $input && $input.length ) {
					$input.attr( 'placeholder', PLACEHOLDERS[ datatype ] || '' );
				}
				if ( datatype === 'wikibase-item' ) {
					valueCombo.$element.addClass( 'wb-entity-combobox' );
					valueCombo.setData( { wbClasses: '' } );
					mw.embeddableContent.entitysuggest.wire( valueCombo.$element );
				} else {
					valueCombo.$element.removeClass( 'wb-entity-combobox' );
					valueCombo.setOptions( [] );
				}
			}

			propertyCombo.on( 'change', updateValue );
			propertyCombo.getMenu().on( 'choose', updateValue );
			updateValue();
		}

		function wireAll( root ) {
			$( root ).find( '.wb-additional-property.oo-ui-comboBoxInputWidget' ).each( function () {
				wireRow( closestRow( $( this ) ) );
			} );
		}

		wireAll( document );
		var pending = null;
		new MutationObserver( function () {
			if ( pending ) {
				return;
			}
			pending = window.setTimeout( function () {
				pending = null;
				wireAll( document );
			}, 100 );
		} ).observe( document.body, { childList: true, subtree: true } );
	} );
}() );
