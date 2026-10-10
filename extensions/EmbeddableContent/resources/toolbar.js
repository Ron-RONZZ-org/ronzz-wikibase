/**
 * Shared ordering for the page action toolbars (the block `.wb-embed-toolbar`
 * under the title on entity/classic per-kind pages, and the inline
 * `.wb-content-page-toolbar` on ordinary content pages).
 *
 * Each toolbar module stamps its control with a canonical rank
 * (`data-wb-order`); add() appends the control and re-sorts the row, so the
 * rendered left→right order is deterministic regardless of module load order
 * and the asynchronous embed/citation API probes — and, because the sort
 * rearranges the DOM, the keyboard tab order matches the visual order.
 *
 * Canonical left→right order:
 *
 *   10  Update basic information / Edit content
 *   20  Copy internal citation
 *   30  Copy internal mention
 *   40  Copy embed code     (the flavour chooser 41, the language select 42
 *                            stay grouped right after the button)
 *   50  Copy citation
 *   60  Print this page
 *
 * Exposes: mw.embeddableContent.toolbar.{ ORDER, add, sort }.
 */
( function () {
	'use strict';

	var ORDER = {
		update: 10,
		citeInternal: 20,
		mention: 30,
		embed: 40,
		embedChooser: 41,
		embedLang: 42,
		citation: 50,
		print: 60
	};

	/** A child's rank; an unranked child sorts after every ranked one. */
	function rank( el ) {
		var value = parseInt( $( el ).attr( 'data-wb-order' ), 10 );
		return isNaN( value ) ? 1000 : value;
	}

	/**
	 * Re-append the row's children in rank order. A stable sort keeps
	 * same-rank controls (e.g. a button and an adjacent widget) in their
	 * insertion order; the row is only touched when the order actually
	 * changes, so a no-op sort never detaches a live node (an open popup, a
	 * focused control).
	 */
	function sort( $container ) {
		var children = $container.children().get();
		if ( children.length < 2 ) {
			return;
		}
		var ordered = children.slice().sort( function ( a, b ) {
			return rank( a ) - rank( b );
		} );
		for ( var i = 0; i < ordered.length; i++ ) {
			if ( ordered[ i ] !== children[ i ] ) {
				$container.append( ordered );
				return;
			}
		}
	}

	/** Stamp $control with `order`, append it, then re-sort the row. */
	function add( $container, $control, order ) {
		$control.attr( 'data-wb-order', order );
		$container.append( $control );
		sort( $container );
		return $control;
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.toolbar = { ORDER: ORDER, add: add, sort: sort };
}() );
