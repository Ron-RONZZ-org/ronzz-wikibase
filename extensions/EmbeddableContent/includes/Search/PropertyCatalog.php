<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Search;

use EmbeddableContent\Spec\AdditionalStatements;
use MediaWiki\MediaWikiServices;
use Wikibase\Repo\WikibaseRepo;

/**
 * The property list for the "Additional semantic statements" comboboxes:
 * every property whose datatype the cloner can write, labelled in the
 * reader's language.
 *
 * The property info table gives the datatype; one term-store query gives
 * the labels (the same `wbt_property_terms` tables Wikibase's own label
 * lookup reads). The list is small on this instance, so the form can render
 * it as a normal combobox and let OOUI filter as the user types.
 *
 * @license GPL-2.0-or-later
 */
final class PropertyCatalog {

	private EmbeddableContentConfig $config;

	/** @var array<string,array{options:array<string,string>,datatypes:array<string,string>}> */
	private array $cache = [];

	public function __construct( \EmbeddableContent\EmbeddableContentConfig $config ) {
		$this->config = $config;
	}

	/**
	 * @return array{options:array<string,string>,datatypes:array<string,string>}
	 *   options: "P123 — label" => "P123"; datatypes: "P123" => datatype
	 */
	public function options( string $language = 'en' ): array {
		if ( isset( $this->cache[$language] ) ) {
			return $this->cache[$language];
		}
		try {
			$supported = array_fill_keys( AdditionalStatements::SUPPORTED_DATATYPES, true );
			$labels = $this->labels( $language );
			$infos = WikibaseRepo::getPropertyInfoLookup()->getAllPropertyInfo();
			$options = [];
			$datatypes = [];
			foreach ( $infos as $propertyId => $info ) {
				$datatype = (string)( $info['type'] ?? '' );
				if ( !isset( $supported[$datatype] ) ) {
					continue;
				}
				$id = (string)$propertyId;
				$label = $id;
				$candidates = array_merge( [ $language ], $this->config->fallbackLanguages(), [ 'en' ] );
				foreach ( $candidates as $code ) {
					if ( isset( $labels[$id][$code] ) && $labels[$id][$code] !== '' ) {
						$label = $labels[$id][$code];
						break;
					}
				}
				if ( $label === $id && !empty( $labels[$id] ) ) {
					$label = (string)reset( $labels[$id] );
				}
				$options[$id . ' — ' . $label] = $id;
				$datatypes[$id] = $datatype;
			}
			uksort( $options, static function ( string $a, string $b ): int {
				return strcasecmp( $a, $b );
			} );
			return $this->cache[$language] = [ 'options' => $options, 'datatypes' => $datatypes ];
		} catch ( \Throwable $e ) {
			// The property catalog is a form convenience; an unavailable
			// term/property-info store must never 500 the Add* page.
			wfDebugLog( 'EmbeddableContent', 'property catalog unavailable: ' . $e->getMessage() );
			return $this->cache[$language] = [ 'options' => [], 'datatypes' => [] ];
		}
	}

	/**
	 * @return array<string,array<string,string>> property id => [language => label]
	 */
	private function labels( string $language ): array {
		$services = MediaWikiServices::getInstance();
		$source = WikibaseRepo::getLocalEntitySource();
		$dbr = $services->getDBLoadBalancer()->getConnection(
			DB_REPLICA,
			[],
			$source->getDatabaseName()
		);
		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'wbpt_property_id', 'wbxl_language', 'wbx_text' ] )
			->from( 'wbt_property_terms' )
			->join( 'wbt_term_in_lang', null, 'wbpt_term_in_lang_id=wbtl_id' )
			->join( 'wbt_text_in_lang', null, 'wbtl_text_in_lang_id=wbxl_id' )
			->join( 'wbt_text', null, 'wbxl_text_id=wbx_id' )
			->where( [ 'wbtl_type_id' => 1 ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$labels = [];
		foreach ( $rows as $row ) {
			$labels['P' . $row->wbpt_property_id][(string)$row->wbxl_language] = (string)$row->wbx_text;
		}
		return $labels;
	}

}
