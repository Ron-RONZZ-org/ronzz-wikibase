/**
 * Vector 2022 typeahead client — classic page titles.
 *
 * The skin's default client uses the REST search endpoint
 * (/rest.php/v1/search/title), which this wiki does not serve (the route
 * falls through to a normal page and 404s), and Wikibase's
 * enableEntitySearchUI replaces the client with an entity-only one. This
 * module is the `searchModule` set by Hooks::onSkinPageReadyConfig: it
 * implements the Vector search-client interface over the stable
 * `action=opensearch` API, so the dropdown suggests classic pages; entities
 * stay in the Special:Search "Items" section.
 *
 * Mirrors wikibase.typeahead.search/init.js (the interface contract):
 * Vector loads this module and calls `init()`.
 */
const client = require( './searchClient.js' );
const skin = mw.config.get( 'skin' );
const moduleName = skin === 'minerva' ? 'skins.minerva.search' : 'skins.vector.search';

function initSearchClient() {
	mw.loader.using( moduleName ).then( () => {
		const { init } = require( moduleName );
		init( client );
	} );
}

module.exports = { init: initSearchClient };
