<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * OpenStreetMap place ids in the canonical `<type>/<id>` form that
 * Nominatim returns and openstreetmap.org dereferences — `node/261512419`,
 * `way/123456789`, `relation/295355`. Stored as external-id statements on
 * the person place properties; the property's formatter URL
 * (https://www.openstreetmap.org/$1) renders the id as a link.
 *
 * @license GPL-2.0-or-later
 */
final class OsmPlace {

	private const ID_PATTERN = '#^(node|way|relation)/[1-9][0-9]*$#i';

	/**
	 * Whether a value is a well-formed OSM place id. Server-side gate for
	 * the AddPerson/UpdatePerson place fields: a harvested place NAME (e.g.
	 * "Cambridge") must be confirmed by the user against the OSM search
	 * combobox — a raw name never passes.
	 */
	public static function isValidId( string $value ): bool {
		return preg_match( self::ID_PATTERN, trim( $value ) ) === 1;
	}
}
