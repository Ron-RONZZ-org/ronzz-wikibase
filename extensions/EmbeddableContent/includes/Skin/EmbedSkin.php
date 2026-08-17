<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Skin;

use MediaWiki\Skin\SkinMustache;

/**
 * Minimal skin for the embed pages (Special:Embed html output): renders the
 * document head (ResourceLoader modules — KaTeX, embed CSS) around just the
 * fragment, with none of the wiki chrome. See templates/embed.mustache.
 *
 * @license GPL-2.0-or-later
 */
class EmbedSkin extends SkinMustache {

	public function __construct( array $options = [] ) {
		$options['name'] = $options['name'] ?? 'embedskin';
		$options['template'] = 'embed';
		$options['templateDirectory'] = __DIR__ . '/templates';
		// SkinTemplate requires the menus key (deprecation otherwise); the
		// embed template does not render menus.
		$options['menus'] = $options['menus'] ?? [
			'namespaces', 'views', 'actions', 'variants', 'personal',
		];
		parent::__construct( $options );
	}
}
