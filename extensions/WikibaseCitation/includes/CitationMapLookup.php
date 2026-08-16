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

	public function getPropertyForField( string $field ): ?string;

	public function getTypeForClass( string $classId ): ?string;
}
