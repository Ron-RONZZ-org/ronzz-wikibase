<?php

declare( strict_types = 1 );

use MediaWiki\MediaWikiServices;
use Wikibase\Repo\WikibaseRepo;
use WikibaseCitation\CitationDependencies;
use WikibaseCitation\CitationEngine;
use WikibaseCitation\CitationFormatter;
use WikibaseCitation\CitationPropertyMap;
use WikibaseCitation\CitationSanitizer;
use WikibaseCitation\CslTypeMapper;
use WikibaseCitation\StatementToCslConverter;

/**
 * Service wiring for WikibaseCitation (issue #6 §7; issue #24 cite-by-QID).
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
		$sourceClasses = $config->get( 'WikibaseCitationSourceClasses' );
		if ( !is_array( $sourceClasses ) || $sourceClasses === [] ) {
			// Fall back to the EmbeddableContent source-class map values.
			$embeddable = $config->get( 'EmbeddableContentConfig' );
			$sourceClasses = is_array( $embeddable ) && isset( $embeddable['sourceClasses'] )
				? array_values( (array)$embeddable['sourceClasses'] )
				: [];
		}
		$partOf = $config->get( 'WikibaseCitationPartOf' );
		if ( !is_string( $partOf ) || $partOf === '' ) {
			// Fall back to the EmbeddableContent sourceProperties map.
			$embeddable = $config->get( 'EmbeddableContentConfig' );
			$partOf = is_array( $embeddable ) && isset( $embeddable['sourceProperties']['partOf'] )
				? $embeddable['sourceProperties']['partOf']
				: null;
		}
		return new StatementToCslConverter(
			WikibaseRepo::getEntityLookup( $services ),
			$services->get( 'WikibaseCitation.PropertyMap' ),
			$services->get( 'WikibaseCitation.CslTypeMapper' ),
			is_string( $instanceOf ) ? $instanceOf : null,
			$sourceClasses,
			is_string( $partOf ) && $partOf !== '' ? $partOf : null
		);
	},

	'WikibaseCitation.CitationFormatter' => static function ( MediaWikiServices $services ): CitationFormatter {
		$styleDir = $services->getMainConfig()->get( 'WikibaseCitationStyleDir' );
		if ( !is_string( $styleDir ) || $styleDir === '' ) {
			$styleDir = dirname( __DIR__ ) . '/styles';
		}
		return new CitationFormatter( $styleDir );
	},

	'WikibaseCitation.CitationSanitizer' => static function ( MediaWikiServices $services ): CitationSanitizer {
		return new CitationSanitizer();
	},

	'WikibaseCitation.CitationEngine' => static function ( MediaWikiServices $services ): CitationEngine {
		return new CitationEngine(
			$services->get( 'WikibaseCitation.StatementToCslConverter' ),
			$services->get( 'WikibaseCitation.CitationFormatter' ),
			WikibaseRepo::getEntityLookup( $services ),
			WikibaseRepo::getEntityRevisionLookup( $services ),
			$services->getMainObjectStash(),
			$services->get( 'WikibaseCitation.CitationSanitizer' ),
			WikibaseRepo::getEntityIdParser( $services )
		);
	},

	'WikibaseCitation.CitationDependencies' => static function ( MediaWikiServices $services ): CitationDependencies {
		// ParserCache invalidation (issue #25 v2): citing pages record
		// templatelinks dependencies on the cited entities + sources, so
		// editing them re-renders the page via RefreshLinksJob.
		return new CitationDependencies(
			WikibaseRepo::getEntityTitleLookup( $services ),
			WikibaseRepo::getEntityRevisionLookup( $services )
		);
	},
];
