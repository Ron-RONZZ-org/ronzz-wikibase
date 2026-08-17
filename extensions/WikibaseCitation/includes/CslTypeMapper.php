<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

/**
 * Maps an item's class (`instance of` value) to a CSL type, from the
 * admin-editable type maps (issue #6 §7: "type map: class → CSL type").
 *
 * Issue #7: the CSL type follows the SOURCE class when the citation has a
 * source item (a book quote cites as `book`, an article quote as `article`),
 * falling back to the content class, then to the default.
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

	/**
	 * Issue #7: source class first, then content class, then default.
	 *
	 * @param string[] $contentClassIds instance-of ids of the content item
	 * @param string[] $sourceClassIds instance-of ids of the source item
	 */
	public function getTypeForContentAndSource( array $contentClassIds, array $sourceClassIds ): string {
		foreach ( $sourceClassIds as $classId ) {
			$type = $this->propertyMap->getTypeForSourceClass( $classId );
			if ( $type !== null ) {
				return $type;
			}
		}
		return $this->getTypeForClasses( $contentClassIds );
	}
}
