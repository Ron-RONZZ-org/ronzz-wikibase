/**
 * Classic-page search client for the Vector 2022 / Minerva typeahead.
 *
 * Uses `action=opensearch` over the reader's search namespaces (the same
 * endpoint core `mediawiki.searchSuggest` uses) instead of the REST search
 * route, which this instance does not expose. Item:/Property: titles do not
 * match label text, so entities do not appear in the dropdown (they are
 * surfaced by the Special:Search "Items" section instead).
 */
( function ( mw ) {
	'use strict';

	/** Entity namespaces (Item:/Property:) — never typeahead suggestions. */
	function entityNamespaces() {
		const ids = mw.config.get( 'wgNamespaceIds' ) || {};
		return [ ids.item, ids.property ].filter( ( id ) => typeof id === 'number' );
	}

	/** Namespace ids the reader searches (the `searchNs*` preference). */
	function searchNamespaces() {
		const namespaces = [];
		const entities = entityNamespaces();
		const formatted = mw.config.get( 'wgFormattedNamespaces' ) || {};
		Object.keys( formatted ).forEach( ( id ) => {
			const ns = Number( id );
			if ( ns >= 0 && !entities.includes( ns )
				&& mw.user.options.get( 'searchNs' + ns )
			) {
				namespaces.push( ns );
			}
		} );
		return namespaces;
	}

	/**
	 * @param {string} query
	 * @param {number} [limit]
	 * @return {{fetch: Promise, abort: Function}}
	 */
	function fetchByTitle( query, limit = 10 ) {
		const api = new mw.Api();
		const params = {
			action: 'opensearch',
			search: query,
			limit,
			format: 'json'
		};
		const namespaces = searchNamespaces();
		if ( namespaces.length ) {
			params.namespace = namespaces;
		}
		const request = api.get( params );
		const fetch = request.then( ( data ) => ( {
			query,
			results: ( data[ 1 ] || [] ).map( ( title, i ) => ( {
				value: title,
				label: title,
				match: '',
				description: ( data[ 2 ] || [] )[ i ] || '',
				url: ( data[ 3 ] || [] ) [ i ] || ''
			} ) )
		} ) );

		return {
			fetch,
			abort: () => api.abort()
		};
	}

	module.exports = {
		fetchByTitle: ( query, limit ) => fetchByTitle( query, limit ),
		// opensearch has no continuation; the skin asks for the next batch on
		// scroll — return an empty page so it stops cleanly.
		loadMore: ( query ) => ( {
			fetch: Promise.resolve( { query, results: [] } ),
			abort: () => {}
		} )
	};
}( mw ) );
