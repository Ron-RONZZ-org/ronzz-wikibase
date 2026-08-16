<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

use MediaWiki\Title\Title;
use MediaWiki\Page\WikiPageFactory;
use Wikimedia\ObjectCache\BagOStuff;

/**
 * Reads the admin-editable citation maps from MediaWiki pages
 * (`MediaWiki:Citation-property-map.json` and `MediaWiki:Citation-type-map.json`,
 * published by maintenance/importCitationMap.php), cached 5 minutes (issue #6 §7).
 *
 * Property map shape:  { "author": "P6", "container-title": "P8", ... }
 * Type map shape:      { "Q1": "article", ... }
 *
 * @license GPL-2.0-or-later
 */
class CitationPropertyMap implements CitationMapLookup {

	private const CACHE_TTL = 300;

	/** @var WikiPageFactory */
	private $wikiPageFactory;

	/** @var BagOStuff */
	private $cache;

	/** @var string */
	private $propertyMapPage;

	/** @var string */
	private $typeMapPage;

	public function __construct(
		WikiPageFactory $wikiPageFactory,
		BagOStuff $cache,
		string $propertyMapPage = 'MediaWiki:Citation-property-map.json',
		string $typeMapPage = 'MediaWiki:Citation-type-map.json'
	) {
		$this->wikiPageFactory = $wikiPageFactory;
		$this->cache = $cache;
		$this->propertyMapPage = $propertyMapPage;
		$this->typeMapPage = $typeMapPage;
	}

	/**
	 * @return array<string,string> CSL field => property id
	 */
	public function propertyMap(): array {
		return $this->loadMap( $this->propertyMapPage );
	}

	/**
	 * @return array<string,string> class item id => CSL type
	 */
	public function typeMap(): array {
		return $this->loadMap( $this->typeMapPage );
	}

	public function getPropertyForField( string $field ): ?string {
		$map = $this->propertyMap();
		return isset( $map[$field] ) && is_string( $map[$field] ) ? $map[$field] : null;
	}

	public function getTypeForClass( string $classId ): ?string {
		$map = $this->typeMap();
		return isset( $map[$classId] ) && is_string( $map[$classId] ) ? $map[$classId] : null;
	}

	/**
	 * @return array<string,string>
	 */
	private function loadMap( string $pageName ): array {
		$key = $this->cache->makeKey( 'WikibaseCitation', 'map', sha1( $pageName ) );
		$cached = $this->cache->get( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$title = Title::newFromText( $pageName );
		$map = [];
		if ( $title !== null && $title->exists() ) {
			$page = $this->wikiPageFactory->newFromTitle( $title );
			$content = $page->getContent();
			if ( $content !== null ) {
				$decoded = json_decode( $content->getText(), true );
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $key => $value ) {
						if ( is_string( $key ) && is_string( $value ) ) {
							$map[$key] = $value;
						}
					}
				}
			}
		}

		$this->cache->set( $key, $map, self::CACHE_TTL );
		return $map;
	}
}
