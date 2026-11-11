/**
 * Licensing for downloaded content — the strings and markup shared by the
 * Download flows in print.js.
 *
 *   - wikitext (.wiki): the notice sentence prepended to the expanded
 *     wikitext (wikitext link syntax, so a re-import renders the link);
 *   - HTML: a footer at the END of the standalone document — the official
 *     88x31 CC BY-NC-SA 4.0 badge plus a one-line notice, both linked to the
 *     licence deed;
 *   - PDF: a print-only licence page inserted after the cover (or after the
 *     title header when no cover) — the four 30x30 deed glyphs (CC, BY, NC,
 *     SA) plus the deed's "You are free to" / "Under the following terms"
 *     summary.
 *
 * The badge is MediaWiki core's bundled `resources/assets/licenses/
 * cc-by-nc-sa.png`; the four glyphs are the Creative Commons press-kit SVGs
 * under resources/license-icons/ (see its README). Both are served from this
 * instance — never a CDN.
 *
 * The content licence itself is the instance licence set in LocalSettings.php
 * ($wgRightsUrl/$wgRightsText/$wgRightsIcon); keep DEED_URL in sync if it
 * ever changes.
 */
( function () {
	'use strict';

	var DEED_URL = 'https://creativecommons.org/licenses/by-nc-sa/4.0/';

	/** Escape a plain string for HTML text/attribute output. */
	function escapeHtml( value ) {
		return $( '<div>' ).text( String( value || '' ) ).html();
	}

	function sitePath( path ) {
		return ( mw.config.get( 'wgServer' ) || '' ) + path;
	}

	/** Absolute URL of the 88x31 CC BY-NC-SA badge (core asset). */
	function badgeUrl() {
		return sitePath(
			mw.config.get( 'wgResourceBasePath' ) +
			'/resources/assets/licenses/cc-by-nc-sa.png'
		);
	}

	/** Absolute URL of one press-kit glyph (cc, by, nc, sa). */
	function glyphUrl( name ) {
		return sitePath(
			mw.config.get( 'wgExtensionAssetsPath' ) +
			'/EmbeddableContent/resources/license-icons/' + name + '.svg'
		);
	}

	/** The exact wikitext notice prepended to a .wiki download. */
	function wikitextNotice() {
		return mw.msg( 'embeddablecontent-download-license-wikitext' );
	}

	/**
	 * The standalone-HTML footer: badge + one-line notice, both linked to the
	 * deed. Plain HTML (not a DOM node) so the caller can embed it in the
	 * generated document string.
	 *
	 * @return {string}
	 */
	function htmlFooter() {
		var alt = escapeHtml( mw.msg( 'embeddablecontent-license-badge-alt' ) );
		var notice = escapeHtml( mw.msg( 'embeddablecontent-license-notice' ) );
		return '<footer class="wb-license">\n'
			+ '<a href="' + DEED_URL + '"><img src="' + escapeHtml( badgeUrl() ) + '"'
			+ ' alt="' + alt + '" width="88" height="31"></a>\n'
			+ '<p><a href="' + DEED_URL + '">' + notice + '</a></p>\n'
			+ '</footer>\n';
	}

	function glyph( name, label ) {
		return '<img class="wb-license-glyph" src="' + escapeHtml( glyphUrl( name ) ) + '"'
			+ ' width="30" height="30" alt="' + escapeHtml( label ) + '">';
	}

	function messageList( keys ) {
		var html = '<ul>';
		keys.forEach( function ( key ) {
			html += '<li>' + escapeHtml( mw.msg( key ) ) + '</li>';
		} );
		return html + '</ul>';
	}

	/**
	 * The print-only licence page (the deed infographic): the four glyphs,
	 * the licence name and the deed's two summary lists.
	 *
	 * @return {string}
	 */
	function pdfPage() {
		return '<div class="wb-print-license">'
			+ '<div class="wb-license-glyphs">'
			+ glyph( 'cc', 'CC' ) + glyph( 'by', 'BY' )
			+ glyph( 'nc', 'NC' ) + glyph( 'sa', 'SA' )
			+ '</div>'
			+ '<h2 class="wb-license-heading">'
			+ escapeHtml( mw.msg( 'embeddablecontent-license-badge-alt' ) ) + '</h2>'
			+ '<p class="wb-license-name">'
			+ escapeHtml( mw.msg( 'embeddablecontent-license-pdf-title' ) ) + '</p>'
			+ '<p><strong>'
			+ escapeHtml( mw.msg( 'embeddablecontent-license-pdf-free' ) ) + '</strong></p>'
			+ messageList( [
				'embeddablecontent-license-pdf-share',
				'embeddablecontent-license-pdf-adapt',
				'embeddablecontent-license-pdf-irrevocable'
			] )
			+ '<p><strong>'
			+ escapeHtml( mw.msg( 'embeddablecontent-license-pdf-terms' ) ) + '</strong></p>'
			+ messageList( [
				'embeddablecontent-license-pdf-attribution',
				'embeddablecontent-license-pdf-noncommercial',
				'embeddablecontent-license-pdf-sharealike',
				'embeddablecontent-license-pdf-noadditional'
			] )
			+ '<p class="wb-license-deed"><a href="' + DEED_URL + '">'
			+ DEED_URL + '</a></p>'
			+ '</div>';
	}

	mw.embeddableContent = mw.embeddableContent || {};
	mw.embeddableContent.license = {
		deedUrl: DEED_URL,
		wikitextNotice: wikitextNotice,
		htmlFooter: htmlFooter,
		pdfPage: pdfPage
	};
}() );
