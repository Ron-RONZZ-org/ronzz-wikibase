/**
 * Vector 2022 quick day/night toggle — a sun/moon icon button in the header,
 * next to the Appearance menu.
 *
 * It deliberately reuses the skin's own `vector-theme` client preference
 * (`skin-theme-clientpref-day|night|os`) and the same class/hook mechanics as
 * Vector's skins.vector.clientPreferences module. That keeps the Appearance
 * menu, the server-rendered theme and this button in sync; no extra theme CSS
 * or state is introduced.
 *
 * Logged-in users persist the choice through `action=options`; anonymous
 * users use the core client-preference cookie (`mw.user.clientPrefs`).
 *
 * The sun/moon SVG paths are the corresponding OOUI accessibility icons
 * (`bright`, `moon`), inlined so the button does not depend on a specific
 * icon-module class name.
 */
( function () {
	'use strict';

	var SUN_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path fill="currentColor" d="M17.07 7.07V2.93h-4.14L10 0 7.07 2.93H2.93v4.14L0 10l2.93 2.93v4.14h4.14L10 20l2.93-2.93h4.14v-4.14L20 10zM10 16a6 6 0 1 1 6-6 6 6 0 0 1-6 6"/><circle cx="10" cy="10" r="4.5" fill="currentColor"/></svg>';
	var MOON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path fill="currentColor" d="M17.39 15.14A7.33 7.33 0 0 1 11.75 1.6c.23-.11.56-.23.79-.34a8.2 8.2 0 0 0-5.41.45 9 9 0 1 0 7 16.58 8.42 8.42 0 0 0 4.29-3.84 5.3 5.3 0 0 1-1.03.69"/></svg>';

	var $button = null;
	var $icon = null;

	/** @return {string} 'day' or 'night' — the EFFECTIVE theme right now. */
	function currentTheme() {
		var classes = document.documentElement.classList;
		if ( classes.contains( 'skin-theme-clientpref-night' ) ) {
			return 'night';
		}
		if ( classes.contains( 'skin-theme-clientpref-os' ) ) {
			return window.matchMedia &&
				window.matchMedia( '(prefers-color-scheme: dark)' ).matches ?
				'night' :
				'day';
		}
		return 'day';
	}

	function updateButton( value ) {
		if ( !$button ) {
			return;
		}
		var dark = value === 'night';
		var label = mw.msg( dark ? 'themetoggle-light' : 'themetoggle-dark' );
		$button.attr( {
			'aria-label': label,
			title: label,
			'aria-pressed': dark ? 'true' : 'false'
		} );
		$icon.html( dark ? SUN_SVG : MOON_SVG );
	}

	/**
	 * @param {string} value 'day' or 'night'
	 */
	function setTheme( value ) {
		var root = document.documentElement;
		var options = [ 'os', 'day', 'night' ];

		if ( mw.user.isNamed() ) {
			options.forEach( function ( option ) {
				root.classList.remove( 'skin-theme-clientpref-' + option );
			} );
			root.classList.add( 'skin-theme-clientpref-' + value );
			// Vector's clientPreferences.js does this too: the layout can
			// change with the theme (T374092).
			window.dispatchEvent( new Event( 'resize' ) );
			mw.hook( 'skin-client-preference.change' ).fire( 'skin-theme', value );
			new mw.Api().saveOption( 'vector-theme', value ).catch( function () {
				mw.notify( mw.msg( 'themetoggle-save-failed' ), { type: 'error' } );
			} );
		} else {
			try {
				mw.user.clientPrefs.set( 'skin-theme', value );
			} catch ( e ) {
				mw.notify( mw.msg( 'themetoggle-save-failed' ), { type: 'error' } );
			}
		}

		// Keep the Appearance menu's radio in sync.
		$( 'input[name="skin-client-pref-skin-theme-group"]' ).prop( 'checked', false );
		$( '#skin-client-pref-skin-theme-value-' + value ).prop( 'checked', true );
		updateButton( value );
	}

	$( function () {
		// The module is only registered on vector-2022, but keep the guard so
		// a manual import on another skin is harmless.
		if ( !document.body.classList.contains( 'skin-vector-2022' ) ) {
			return;
		}
		// Night mode is opt-out per page via VectorNightModeOptions; do not
		// offer a button that cannot take effect on an excluded page.
		if ( document.documentElement.classList.contains( 'skin-theme-clientpref--excluded' ) ) {
			return;
		}
		var $landmark = $( '.vector-user-links-main .vector-appearance-landmark' ).first();
		if ( !$landmark.length ) {
			return;
		}

		$icon = $( '<span>' ).addClass( 'wb-theme-toggle-icon' );
		$button = $( '<button>' )
			.attr( { type: 'button', id: 'wb-theme-toggle' } )
			.addClass(
				'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled ' +
				'cdx-button--weight-quiet cdx-button--icon-only wb-theme-toggle'
			)
			.append( $icon )
			.on( 'click', function () {
				setTheme( currentTheme() === 'night' ? 'day' : 'night' );
			} );
		$landmark.before( $button );
		updateButton( currentTheme() );

		// Keep the icon in sync when the user picks a color in the Appearance
		// menu, or when another script changes the same client preference.
		$( document ).on( 'change', 'input[name="skin-client-pref-skin-theme-group"]', function () {
			updateButton( currentTheme() );
		} );
		mw.hook( 'skin-client-preference.change' ).add( function ( feature, value ) {
			if ( feature === 'skin-theme' ) {
				updateButton( value === 'os' ? currentTheme() : value );
			}
		} );
	} );
}() );
