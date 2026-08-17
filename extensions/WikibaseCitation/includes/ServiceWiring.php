<?php

declare( strict_types = 1 );

use MediaWiki\MediaWikiServices;
use Wikibase\Repo\WikibaseRepo;
use WikibaseCitation\CitationFormatter;
use WikibaseCitation\CitationPropertyMap;
use WikibaseCitation\CslTypeMapper;
use WikibaseCitation\StatementToCslConverter;

/**
 * Service wiring for WikibaseCitation (issue #6 §7).
 *
 * @license GPL-2.0-or-later
 */
return [
	'WikibaseCitation.PropertyMap' => static function ( MediaWikiServices $services ): CitationPropertyMap {
		return new CitationPropertyMap(
			$services->getWikiPageFactory(),
			$services->getMainObjectStash()
		);
	},

	'WikibaseCitation.CslTypeMapper' => static function ( MediaWikiServices $services ): CslTypeMapper {
		return new CslTypeMapper( $services->get( 'WikibaseCitation.PropertyMap' ) );
	},

	'WikibaseCitation.StatementToCslConverter' => static function ( MediaWikiServices $services ): StatementToCslConverter {
		$config = $services->getMainConfig();
		$instanceOf = $config->get( 'WikibaseCitationInstanceOf' );
		if ( !is_string( $instanceOf ) || $instanceOf === '' ) {
			// Fall back to the EmbeddableContent config map when present.
			$embeddable = $config->get( 'EmbeddableContentConfig' );
			$instanceOf = is_array( $embeddable ) ? ( $embeddable['instanceOf'] ?? null ) : null;
		}
		return new StatementToCslConverter(
			WikibaseRepo::getEntityLookup( $services ),
			$services->get( 'WikibaseCitation.PropertyMap' ),
			$services->get( 'WikibaseCitation.CslTypeMapper' ),
			is_string( $instanceOf ) ? $instanceOf : null
		);
	},

	'WikibaseCitation.CitationFormatter' => static function ( MediaWikiServices $services ): CitationFormatter {
		$styleDir = $services->getMainConfig()->get( 'WikibaseCitationStyleDir' );
		if ( !is_string( $styleDir ) || $styleDir === '' ) {
			$styleDir = dirname( __DIR__ ) . '/styles';
		}
		return new CitationFormatter( $styleDir );
	},
];
