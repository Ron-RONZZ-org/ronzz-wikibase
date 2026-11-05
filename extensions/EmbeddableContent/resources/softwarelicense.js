/**
 * Special:AddSoftware — the "Unknown license" checkbox (below the required
 * license field) disables and clears the license combobox, so the form
 * submits without a license. The server also clears the value in
 * SpecialAddSoftware::beforeCreate (the checkbox wins over a stale value)
 * and then classifies the item as non-FOSS (Software: page).
 *
 * Create-only: Special:UpdateSoftware does not render the checkbox, so this
 * module is a no-op there (and is not loaded).
 */
( function () {
	'use strict';

	function licenseInputs() {
		var $field = $( '#wplicense' );
		if ( $field.length === 0 ) {
			$field = $( 'input[name="wplicense"]' );
		}
		return $field.find( 'input' ).addBack( 'input' );
	}

	function sync() {
		var checkbox = document.getElementById( 'wplicenseUnknown' );
		if ( !checkbox ) {
			return;
		}
		var $inputs = licenseInputs();
		var unknown = checkbox.checked;
		$inputs.prop( 'disabled', unknown );
		if ( unknown ) {
			$inputs.val( '' ).trigger( 'change' );
		}
	}

	$( function () {
		var checkbox = document.getElementById( 'wplicenseUnknown' );
		if ( !checkbox ) {
			return;
		}
		checkbox.addEventListener( 'change', sync );
		sync();
	} );
}() );
