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
 * The block row lives INSIDE `#bodyContent`, immediately before
 * `#mw-content-text` — below the whole page-title chrome. Vector 2022 puts
 * `#firstHeading` inside `.vector-page-titlebar` (a flex row), so
 * `$('#firstHeading').after(row)` made the row a flex item BESIDE the title:
 * a long content preview then squeezed the h1 to one letter per line (the
 * Item:Q2274 report). A sibling of `#bodyContent` is equally wrong: the
 * Vector 2022 `main#content` is a CSS grid, so an unplaced child lands in the
 * spare column and squeezes the content column.
 *
 * Exposes:
 *   mw.embeddableContent.toolbar.{ ORDER, add, sort, insertBelowTitle, getRow }.
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

	/**
	 * Insert a block-level node below the page-title chrome.
	 *
	 * The node goes INSIDE `#bodyContent`, immediately before
	 * `#mw-content-text` (fallback: at the top of `#bodyContent`). It must NOT
	 * be inserted after `#firstHeading` — Vector 2022 wraps that h1 in the flex
	 * `.vector-page-titlebar`, so the node becomes a flex item beside the title
	 * and a long preview squeezes the heading to one letter per line.
	 * Nor may it be a sibling of `#bodyContent`: `main#content` is a CSS GRID
	 * in Vector 2022, and an unplaced child lands in the spare grid column,
	 * squeezing the whole content column (found live on the first deploy of
	 * this fix).
	 *
	 * @param {jQuery} $node
	 * @return {jQuery} $node
	 */
	function insertBelowTitle( $node ) {
		var $contentText = $( '#mw-content-text' );
		if ( $contentText.length > 0 ) {
			$contentText.before( $node );
			return $node;
		}
		var $body = $( '#bodyContent' );
		if ( $body.length > 0 ) {
			$body.prepend( $node );
			return $node;
		}
		$( '#firstHeading' ).after( $node );
		return $node;
	}

	/**
	 * The shared block toolbar row, created on first use. Found inside
	 * `#bodyContent` before `#mw-content-text`, so a dialog-local
	 * `.wb-embed-toolbar` (the Add* success popup) is never mistaken for the
	 * page row.
	 *
	 * @return {jQuery}
	 */
	function getRow() {
		var $contentText = $( '#mw-content-text' );
		var $row = $contentText.length > 0
			? $contentText.prevAll( '.wb-embed-toolbar' ).first()
			: $( '#bodyContent' ).children( '.wb-embed-toolbar' ).first();
		if ( $row.length === 0 ) {
			$row = insertBelowTitle( $( '<div class="wb-embed-toolbar"></div>' ) );
		}
		return $row;
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.toolbar = {
		ORDER: ORDER,
		add: add,
		sort: sort,
		insertBelowTitle: insertBelowTitle,
		getRow: getRow
	};
}() );
