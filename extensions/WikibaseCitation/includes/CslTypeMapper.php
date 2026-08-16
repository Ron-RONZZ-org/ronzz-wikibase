<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

/**
 * Maps an item's class (`instance of` value) to a CSL type, from the
 * admin-editable type map (issue #6 §7: "type map: class → CSL type").
 *
 * @license GPL-2.0-or-later
 */
class CslTypeMapper {

	/** Default CSL type when the class is unknown or unmapped. */
	public const DEFAULT_TYPE = 'article';

	/** @var CitationMapLookup */
	private $propertyMap;

	public function __construct( CitationMapLookup $propertyMap ) {
		$this->propertyMap = $propertyMap;
	}

	/**
	 * @param string[] $classIds all `instance of` class ids of the item
	 */
	public function getTypeForClasses( array $classIds ): string {
		foreach ( $classIds as $classId ) {
			$type = $this->propertyMap->getTypeForClass( $classId );
			if ( $type !== null ) {
				return $type;
			}
		}
		return self::DEFAULT_TYPE;
	}
}
