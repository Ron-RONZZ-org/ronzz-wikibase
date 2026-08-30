<?php

declare( strict_types = 1 );

use EmbeddableContent\Content\ContentRenderer;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Fetch\CurlHttpClient;
use EmbeddableContent\Fetch\NominatimProvider;
use EmbeddableContent\Fetch\ProviderClient;
use EmbeddableContent\Fetch\RateLimitedHttpClient;
use EmbeddableContent\Fetch\WikipediaContentProvider;
use MediaWiki\MediaWikiServices;
use Wikibase\Repo\WikibaseRepo;

/**
 * Service wiring for EmbeddableContent (issue #6 §4.2; issue #7 fetch layer).
 *
 * @license GPL-2.0-or-later
 */
return [
	'EmbeddableContent.Config' => static function ( MediaWikiServices $services ): EmbeddableContentConfig {
		return new EmbeddableContentConfig(
			$services->getMainConfig()->get( 'EmbeddableContentConfig' )
		);
	},

	'EmbeddableContent.ContentRenderer' => static function ( MediaWikiServices $services ): ContentRenderer {
		return new ContentRenderer(
			$services->get( 'EmbeddableContent.Config' ),
			WikibaseRepo::getEntityLookup( $services ),
			WikibaseRepo::getEntityRevisionLookup( $services ),
			$services->getMainObjectStash()
		);
	},

	'EmbeddableContent.ProviderClient' => static function ( MediaWikiServices $services ): ProviderClient {
		// External-authority fetch layer (issue #7): SSRF allowlist enforced
		// inside CurlHttpClient; the canonical cascade wiring from #7 §Fetch.
		// The YouTube key is deploy-injected (config map, never committed);
		// '' disables the provider. The name-search cache TTL defaults to 0
		// (off) — a config flip, not a code change.
		//
		// The whole cascade shares ONE rate-limited transport: WMF endpoints
		// (Wikidata hub, Wikipedia content) are throttled to the instance's
		// polite anonymous budget, so the shared Oracle-Cloud IP does not
		// draw the Wikimedia 429 blocks (fceb99d).
		$config = $services->get( 'EmbeddableContent.Config' );
		return ProviderClient::default(
			new RateLimitedHttpClient( new CurlHttpClient() ),
			10.0,
			$config->youtubeApiKey(),
			10,
			$services->getMainObjectStash(),
			$config->youtubeSearchCacheTtl()
		);
	},

	'EmbeddableContent.WikipediaContent' => static function ( MediaWikiServices $services ): WikipediaContentProvider {
		// Fixed-host (en.wikipedia.org) content provider behind the shared
		// WMF rate limiter — the lead-intro/Plot/Lyrics fetch used by the
		// Add* page-content step.
		return new WikipediaContentProvider(
			new RateLimitedHttpClient( new CurlHttpClient( [ 'en.wikipedia.org' ] ) )
		);
	},

	'EmbeddableContent.Nominatim' => static function ( MediaWikiServices $services ): NominatimProvider {
		// Fixed-host OpenStreetMap Nominatim search (osm-places): the
		// harvest-on-pick label→OSM auto-match. Pinned allowlist + the
		// shared rate limiter at Nominatim's usage-policy minimum (1 req/s).
		return new NominatimProvider(
			new RateLimitedHttpClient( new CurlHttpClient( [ 'nominatim.openstreetmap.org' ] ), 1.1 )
		);
	},
];
