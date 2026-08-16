<?php

declare( strict_types = 1 );

use EmbeddableContent\Content\ContentRenderer;
use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\MediaWikiServices;
use Wikibase\Repo\WikibaseRepo;

/**
 * Service wiring for EmbeddableContent (issue #6 §4.2).
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
];
