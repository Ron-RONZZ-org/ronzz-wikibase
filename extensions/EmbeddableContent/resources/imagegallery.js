/**
 * One-at-a-time infobox image gallery with previous/next buttons
 * ({{#item-image:}} on Person:/Collective:/Software:/FOSS: pages).
 */
( function () {
	'use strict';

	function setup( gallery ) {
		var track = gallery.querySelector( '.wb-image-gallery-track' );
		if ( !track ) {
			return;
		}
		var slides = Array.prototype.slice.call( track.children );
		if ( slides.length < 2 ) {
			return;
		}
		var index = 0;
		function render() {
			slides.forEach( function ( slide, i ) {
				slide.hidden = i !== index;
			} );
			gallery.setAttribute( 'data-index', String( index ) );
		}
		var prev = gallery.querySelector( '.wb-image-gallery-prev' );
		var next = gallery.querySelector( '.wb-image-gallery-next' );
		if ( prev ) {
			prev.addEventListener( 'click', function () {
				index = ( index - 1 + slides.length ) % slides.length;
				render();
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function () {
				index = ( index + 1 ) % slides.length;
				render();
			} );
		}
		render();
	}

	function setupAll() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.wb-image-gallery' ),
			setup
		);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', setupAll );
	} else {
		setupAll();
	}
}() );
