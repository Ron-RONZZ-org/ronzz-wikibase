/**
 * Per-section copy controls on ordinary content pages: two small controls
 * rendered to the RIGHT of each heading's "[edit]" link (the core
 * `.mw-editsection`):
 *
 *   - "Copy internal mention" — copies `[[Page title#section anchor|` (NO
 *     closing brackets — the contributor types the display text themselves),
 *     so a section can be linked/mentioned from anywhere on the wiki;
 *   - "Copy URL" — copies the ABSOLUTE URL of the section
 *     (`https://host/wiki/Page#section-anchor`).
 *
 * The page-title part of the mention reuses the server-resolved
 * `wbReferenceSnippet` (`Spec\MentionSnippet`, the same Main-namespace
 * case-normalized `[[Page name]]` the page-level "Copy internal mention"
 * button copies) — this module only strips the closing brackets and inserts
 * the section anchor after a pipe-less `#`. The server sets the config var
 * (and loads this module) on the same content-page branch as the page-level
 * toolbar.
 *
 * The section anchor is the heading's `id` — MediaWiki already normalizes it
 * (spaces → underscores, special characters encoded), so it is valid both in
 * a wikitext `#…` fragment and in a URL.
 */
( function () {
	'use strict';

	/** Copy `text`, resolving true on success and false on failure. */
	function copyText( text ) {
		return new Promise( function ( resolve ) {
			var done = function ( ok ) {
				if ( ok ) {
					mw.notify( mw.msg( 'embeddablecontent-gadget-copied' ) );
				}
				resolve( ok );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then(
					function () { done( true ); },
					function () { done( fallbackCopy( text ) ); }
				);
				return;
			}
			done( fallbackCopy( text ) );
		} );
	}

	/** @return {boolean} whether the legacy copy command reported success */
	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		var ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		} finally {
			document.body.removeChild( ta );
		}
		return ok;
	}

	/**
	 * The heading anchor of one `.mw-editsection`. MW 1.43+ wraps the heading:
	 * `<div class="mw-heading"><h2 id="Anchor">…</h2><span class="mw-editsection">…</span></div>`;
	 * the id sits on the heading element (older markup) or, defensively, on
	 * the wrapper. Returns null when no anchor can be resolved.
	 *
	 * @param {jQuery} $edit the `.mw-editsection` element
	 * @return {?string}
	 */
	function sectionAnchor( $edit ) {
		var $wrapper = $edit.parent();
		var $heading = $wrapper.children( 'h1, h2, h3, h4, h5, h6' ).first();
		if ( $heading.length === 0 ) {
			$heading = $edit.prevAll( 'h1, h2, h3, h4, h5, h6' ).first();
		}
		if ( $heading.length && $heading.attr( 'id' ) ) {
			return $heading.attr( 'id' );
		}
		if ( $wrapper.length && $wrapper.attr( 'id' ) ) {
			return $wrapper.attr( 'id' );
		}
		return null;
	}

	/** One small copy control matching the [edit] link's style. */
	function makeButton( id, messageKey, hintKey, text ) {
		return $( '<button>' )
			.attr( 'id', id )
			.attr( 'type', 'button' )
			.addClass( 'wb-section-action' )
			.attr( 'title', mw.msg( hintKey ) )
			.text( mw.msg( messageKey ) )
			.on( 'click', function ( e ) {
				e.preventDefault();
				copyText( text );
			} );
	}

	mw.loader.using( [ 'mediawiki.notification', 'mediawiki.util' ] ).then( function () {
		var snippet = mw.config.get( 'wbReferenceSnippet' );
		if ( !snippet || $( '#firstHeading' ).length === 0 ) {
			return;
		}
		// `[[Page name]]` → `[[Page name` (the mention pipes the anchor in).
		var linkBase = String( snippet ).replace( /\]\]\s*$/, '' );
		var pageName = mw.config.get( 'wgPageName' );
		var pageUrlBase = location.origin + mw.util.getUrl( pageName );

		$( '.mw-editsection' ).each( function () {
			var $edit = $( this );
			if ( $edit.data( 'wbSectionActions' ) ) {
				return;
			}
			$edit.data( 'wbSectionActions', true );
			var anchor = sectionAnchor( $edit );
			if ( !anchor ) {
				return;
			}
			var mention = linkBase + '#' + anchor + '|';
			var url = pageUrlBase + '#' + anchor;
			// Append INSIDE the `.mw-editsection` (after the closing bracket,
			// which is its last child) so the controls inherit its small inline
			// link style — the "[edit] mention url" row.
			var $actions = $( '<span class="wb-section-actions"></span>' )
				.append( document.createTextNode( ' ' ) )
				.append( makeButton(
					'wb-section-copymention-' + anchor,
					'embeddablecontent-section-copymention',
					'embeddablecontent-section-copymention-hint',
					mention
				) )
				.append( document.createTextNode( ' ' ) )
				.append( makeButton(
					'wb-section-copyurl-' + anchor,
					'embeddablecontent-section-copyurl',
					'embeddablecontent-section-copyurl-hint',
					url
				) );
			$edit.append( $actions );
		} );
	} );
}() );
