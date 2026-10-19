/**
 * Per-section controls on ordinary content pages, rendered INSIDE each
 * heading's `.mw-editsection` next to the core `[edit]` link, each in its own
 * bracket pair (the `[edit]` style):
 *
 *   [edit] [Copy internal mention] [Copy URL] [Add section below] [Delete section]
 *
 *   - "Copy internal mention" — copies `[[Page title#section anchor|` (NO
 *     closing brackets — the contributor types the display text themselves);
 *   - "Copy URL" — copies the ABSOLUTE URL of the section;
 *   - "Add section below" — opens the append editor targeting this section
 *     (`?action=appendsection&wbsection=<n>`; the number is read from the
 *     existing `[edit]` link. It rides `wbsection`, not `section`, because
 *     the append editor is core's `EditPage`, whose own `section` parameter
 *     must stay empty);
 *   - "Delete section" — opens the delete confirmation
 *     (`?action=deletesection&section=<n>`).
 *
 * The page-title part of the mention reuses the server-resolved
 * `wbReferenceSnippet` (`Spec\MentionSnippet`, the same Main-namespace
 * case-normalized `[[Page name]]` the page-level "Copy internal mention"
 * button copies) — this module only strips the closing brackets and inserts
 * the section anchor after a pipe-less `#`. The server sets the config var
 * (and loads this module) on the same content-page branch as the page-level
 * toolbar.
 *
 * The section anchor preference is:
 *   1. an explicit `{{anchor|…}}` target (the contributor's choice);
 *   2. the automatic anchor the server injects for a heading whose generated
 *      id contains math or an illegal fragment character
 *      (`Spec\HeadingAnchors`, `<span class="wb-section-auto-anchor">`);
 *   3. the heading's own `id`.
 * MediaWiki normalizes all three (spaces → underscores), so they are valid
 * both in a wikitext `#…` fragment and in a URL. A heading whose generated
 * id contains math/formatting characters (`$ \ { } [ ] | < >`) also carries
 * a legacy escaped `<span id>`; the explicit/anchor spans follow it, so the
 * LAST non-automatic empty span is the explicit one.
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
	 * The explicit `{{anchor|…}}` target inside a heading, if any.
	 *
	 * MediaWiki prepends its own empty legacy anchor span when the heading id
	 * contains characters that cannot appear in a link (e.g. the math heading
	 * `<h4 id="$\operatorname{sign}(…)$"><span id=".24.5C…"></span>`).
	 * `Template:Anchor` then adds one empty span per anchor, so the explicit
	 * anchor(s) follow the legacy span: prefer the LAST empty span whose id
	 * differs from the heading id. A single span on a linkable heading is an
	 * explicit anchor; a single span on an escaped heading is the legacy id.
	 * The server-injected automatic anchor (`wb-section-auto-anchor`) is a
	 * fallback, not an explicit choice, so it is skipped here.
	 *
	 * @param {jQuery} $heading
	 * @return {?string}
	 */
	function explicitAnchor( $heading ) {
		var headingId = $heading.attr( 'id' ) || '';
		var candidates = [];
		$heading.find( 'span[id]' ).each( function () {
			var $span = $( this );
			if ( $span.hasClass( 'wb-section-auto-anchor' ) ) {
				return;
			}
			if ( $span.children().length > 0 || $span.text() !== '' ) {
				return;
			}
			if ( this.id && this.id !== headingId ) {
				candidates.push( this.id );
			}
		} );
		if ( candidates.length === 0 ) {
			return null;
		}
		if ( candidates.length > 1 ) {
			// The legacy escaped span comes first; the explicit anchors follow.
			return candidates[ candidates.length - 1 ];
		}
		// A single span is the explicit anchor when the heading id is linkable;
		// when the heading id needs escaping, the lone span is MediaWiki's
		// legacy encoded id — keep the heading-id fallback instead.
		if ( /[\\${}\[\]|<>]/.test( headingId ) ) {
			return null;
		}
		return candidates[ 0 ];
	}

	/**
	 * The automatic anchor the server injected for a heading whose generated
	 * id cannot be used in a fragment (math / illegal characters). Scoped
	 * class, so an explicit `{{anchor}}` always remains the first choice.
	 *
	 * @param {jQuery} $heading
	 * @return {?string}
	 */
	function autoAnchor( $heading ) {
		var $span = $heading.find( 'span.wb-section-auto-anchor[id]' ).last();
		return $span.length > 0 && $span.attr( 'id' ) ? $span.attr( 'id' ) : null;
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
		if ( $heading.length ) {
			var explicit = explicitAnchor( $heading );
			if ( explicit ) {
				return explicit;
			}
			var auto = autoAnchor( $heading );
			if ( auto ) {
				return auto;
			}
			if ( $heading.attr( 'id' ) ) {
				return $heading.attr( 'id' );
			}
		}
		if ( $wrapper.length && $wrapper.attr( 'id' ) ) {
			return $wrapper.attr( 'id' );
		}
		return null;
	}

	/**
	 * The section index the core `[edit]` link targets (`?…&section=<n>`), or
	 * null. The same number drives `action=appendsection` / `deletesection`.
	 *
	 * @param {jQuery} $edit the `.mw-editsection` element
	 * @return {?number}
	 */
	function sectionNumber( $edit ) {
		var href = $edit.find( 'a' ).first().attr( 'href' ) || '';
		var value = null;
		try {
			value = new URL( href, window.location.href ).searchParams.get( 'section' );
		} catch ( e ) {
			value = null;
		}
		if ( value === null ) {
			return null;
		}
		var number = parseInt( value, 10 );
		return isNaN( number ) || number <= 0 ? null : number;
	}

	/** Wrap one control in its own `[ ]` bracket pair, with a leading space. */
	function bracketed( $control ) {
		return $( '<span class="wb-section-action-group"></span>' )
			.append( document.createTextNode( ' ' ) )
			.append( $( '<span class="mw-editsection-bracket"></span>' ).text( '[' ) )
			.append( $control )
			.append( $( '<span class="mw-editsection-bracket"></span>' ).text( ']' ) );
	}

	/** One small copy control matching the [edit] link's style. */
	function makeCopyButton( id, messageKey, hintKey, text ) {
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

	/** One small navigation control matching the [edit] link's style. */
	function makeActionLink( id, messageKey, hintKey, href ) {
		return $( '<a>' )
			.attr( 'id', id )
			.attr( 'href', href )
			.addClass( 'wb-section-action' )
			.attr( 'title', mw.msg( hintKey ) )
			.text( mw.msg( messageKey ) );
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

			$edit.append( bracketed( makeCopyButton(
				'wb-section-copymention-' + anchor,
				'embeddablecontent-section-copymention',
				'embeddablecontent-section-copymention-hint',
				linkBase + '#' + anchor + '|'
			) ) );
			$edit.append( bracketed( makeCopyButton(
				'wb-section-copyurl-' + anchor,
				'embeddablecontent-section-copyurl',
				'embeddablecontent-section-copyurl-hint',
				pageUrlBase + '#' + encodeURIComponent( anchor )
			) ) );

			var number = sectionNumber( $edit );
			if ( number === null ) {
				return;
			}
			$edit.append( bracketed( makeActionLink(
				'wb-section-addbelow-' + anchor,
				'embeddablecontent-section-addbelow',
				'embeddablecontent-section-addbelow-hint',
				mw.util.getUrl( pageName, { action: 'appendsection', wbsection: number } )
			) ) );
			$edit.append( bracketed( makeActionLink(
				'wb-section-delete-' + anchor,
				'embeddablecontent-section-delete',
				'embeddablecontent-section-delete-hint',
				mw.util.getUrl( pageName, { action: 'deletesection', section: number } )
			) ) );
		} );
	} );
}() );
