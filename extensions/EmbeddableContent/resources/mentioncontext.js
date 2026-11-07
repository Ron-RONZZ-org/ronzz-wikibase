/**
 * Right-click menu for the "Copy internal mention" buttons.
 *
 * A plain click still copies the page's `[[Page name]]` snippet. A
 * right-click opens a small menu with the default copy and the
 * "Capitalise first letter" variant — the page name's first letter
 * uppercased (server-resolved as wbReferenceSnippetCapitalized /
 * wbMentionLinkCapitalized), leaving the namespace prefix untouched.
 *
 * The caller owns the clipboard write (so each surface keeps its existing
 * fallback/notification), and passes:
 *   { plain: function () -> string,
 *     capitalized: function () -> string,
 *     copy: function ( string ) }
 *
 * Exposes mw.embeddableContent.mentionContext.attach( $button, snippets ).
 */
( function () {
	'use strict';

	/** The currently open menu, or null. */
	var $openMenu = null;

	function closeMenu() {
		if ( !$openMenu ) {
			return;
		}
		$openMenu.remove();
		$openMenu = null;
		$( document ).off( '.wbMentionMenu' );
		$( window ).off( '.wbMentionMenu' );
	}

	/**
	 * Position the fixed menu at the pointer, clamped to the viewport.
	 *
	 * @param {jQuery} $menu
	 * @param {number} x
	 * @param {number} y
	 */
	function placeMenu( $menu, x, y ) {
		$menu.css( { left: x, top: y } );
		var rect = $menu[ 0 ].getBoundingClientRect();
		var maxX = window.innerWidth - rect.width - 4;
		var maxY = window.innerHeight - rect.height - 4;
		$menu.css( {
			left: Math.max( 4, Math.min( x, maxX ) ),
			top: Math.max( 4, Math.min( y, maxY ) )
		} );
	}

	/**
	 * Build and show the menu.
	 *
	 * @param {jQuery} $trigger
	 * @param {jQuery.Event} event
	 * @param {{plain:Function, capitalized:Function, copy:Function}} snippets
	 */
	function openMenu( $trigger, event, snippets ) {
		closeMenu();
		var items = [
			{ variant: 'plain', label: mw.msg( 'embeddablecontent-mention-menu-copy' ) },
			{ variant: 'capitalized', label: mw.msg( 'embeddablecontent-mention-menu-capitalise' ) }
		];
		if ( snippets.authorYear ) {
			items.push( {
				variant: 'authorYear',
				label: mw.msg( 'embeddablecontent-mention-menu-author-year' )
			} );
		}
		var $menu = $( '<div>' )
			.addClass( 'wb-mention-menu' )
			.attr( 'role', 'menu' );
		items.forEach( function ( item ) {
			$( '<button>' )
				.attr( { type: 'button', role: 'menuitem' } )
				.addClass( 'wb-mention-menu-item' )
				.text( item.label )
				.on( 'click', function () {
					closeMenu();
					var text;
					if ( item.variant === 'capitalized' ) {
						text = snippets.capitalized();
					} else if ( item.variant === 'authorYear' ) {
						text = snippets.authorYear();
					} else {
						text = snippets.plain();
					}
					snippets.copy( text );
				} )
				.appendTo( $menu );
		} );
		$( document.body ).append( $menu );
		placeMenu( $menu, event.clientX || 0, event.clientY || 0 );
		$openMenu = $menu;
		$menu.find( '.wb-mention-menu-item' ).first().trigger( 'focus' );
		// `mousedown` (not `click`) so the right-click that opened the menu
		// cannot immediately close it; a click inside the menu is ignored.
		$( document ).on( 'mousedown.wbMentionMenu', function ( e ) {
			if ( $openMenu && !$openMenu[ 0 ].contains( e.target ) ) {
				closeMenu();
			}
		} );
		$( document ).on( 'keydown.wbMentionMenu', function ( e ) {
			if ( e.key === 'Escape' ) {
				closeMenu();
				$trigger.trigger( 'focus' );
			}
		} );
		$( window ).on( 'resize.wbMentionMenu scroll.wbMentionMenu', closeMenu );
	}

	/**
	 * Attach the context menu to a mention button.
	 *
	 * @param {jQuery} $button
	 * @param {{plain:Function, capitalized:Function, copy:Function}} snippets
	 */
	function attach( $button, snippets ) {
		$button.attr( 'aria-haspopup', 'menu' );
		$button.on( 'contextmenu', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			openMenu( $button, e, snippets );
		} );
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.mentionContext = { attach: attach };
}() );
