<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

/**
 * Minimal read surface over the citation maps, so the converter and type
 * mapper are unit-testable with a fake (no MediaWiki needed).
 *
 * @license GPL-2.0-or-later
 */
interface CitationMapLookup {

	/** CSL field → property id, resolved on the CONTENT item. */
	public function getPropertyForField( string $field ): ?string;

	/** CSL field → property id, resolved on the SOURCE item (issue #7 full harvest). */
	public function getSourcePropertyForField( string $field ): ?string;

	/** Content class item id → CSL type. */
	public function getTypeForClass( string $classId ): ?string;

	/** Source class item id → CSL type (issue #7: type follows source class). */
	public function getTypeForSourceClass( string $classId ): ?string;
}
