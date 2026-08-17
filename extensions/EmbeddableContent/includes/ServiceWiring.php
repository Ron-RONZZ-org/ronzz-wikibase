<?php

declare( strict_types = 1 );

use EmbeddableContent\Content\ContentRenderer;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Fetch\CurlHttpClient;
use EmbeddableContent\Fetch\ProviderClient;
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
		return ProviderClient::default( new CurlHttpClient() );
	},
];
