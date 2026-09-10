<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Pure helpers for the multi-value territorial-jurisdiction field of the
 * legal source classes (legalCase / legislation / bill / treaty).
 *
 * The form submits a comma/semicolon-separated list of OpenStreetMap ids
 * (`node|way|relation/<id>`) plus a JSON object mapping each id to its
 * human-readable Nominatim display name. The label field also accepts the
 * LEGACY plain-string form (a single label) so items created before the
 * multi-value rework keep rendering.
 *
 * All four consumers go through these helpers — the Add form's beforeCreate,
 * the flow service's statementSpecs, the {{#osm-place:jurisdiction}} renderer
 * and the Update prefill — so the encoding can never drift.
 *
 * @license GPL-2.0-or-later
 */
final class JurisdictionList {

	/**
	 * The trimmed, non-empty comma/semicolon-separated segments of a raw
	 * field value (invalid segments included — callers validate).
	 *
	 * @return string[]
	 */
	public static function segments( string $value ): array {
		$out = [];
		foreach ( preg_split( '/[,;]/', $value ) ?: [] as $segment ) {
			$segment = trim( $segment );
			if ( $segment !== '' ) {
				$out[] = $segment;
			}
		}
		return $out;
	}

	/**
	 * Whether every non-empty segment is a well-formed OSM id. An empty
	 * value is valid (the field is optional).
	 */
	public static function allValid( string $value ): bool {
		foreach ( self::segments( $value ) as $segment ) {
			if ( !OsmPlace::isValidId( $segment ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The valid, deduplicated OSM ids of a raw field value (invalid segments
	 * dropped — the caller checks allValid() first when it must reject them).
	 *
	 * @return string[]
	 */
	public static function split( string $value ): array {
		$out = [];
		foreach ( self::segments( $value ) as $segment ) {
			if ( OsmPlace::isValidId( $segment ) && !in_array( $segment, $out, true ) ) {
				$out[] = $segment;
			}
		}
		return $out;
	}

	/**
	 * The id => label map restricted to the given ids. $raw is either the
	 * current JSON object or the legacy plain label string (attached to the
	 * first id).
	 *
	 * @param string[] $ids
	 * @return array<string,string>
	 */
	public static function labels( string $raw, array $ids ): array {
		$raw = trim( $raw );
		if ( $raw === '' || $ids === [] ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$out = [];
			foreach ( $ids as $id ) {
				$label = $decoded[$id] ?? null;
				if ( is_string( $label ) && trim( $label ) !== '' ) {
					$out[$id] = trim( $label );
				}
			}
			return $out;
		}
		// Legacy plain label (pre-multi-value items): one label, one id.
		return [ $ids[0] => $raw ];
	}

	/**
	 * Serializes an id => label map for storage ('' when empty).
	 *
	 * @param array<string,string> $labels
	 */
	public static function encodeLabels( array $labels ): string {
		if ( $labels === [] ) {
			return '';
		}
		return (string)json_encode( $labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * The wikitext for a jurisdiction cell: one `[url label]` per id, joined
	 * by ", ". The raw id is the link text when no label is known.
	 *
	 * @param string[] $ids
	 * @param array<string,string> $labels
	 */
	public static function links( array $ids, array $labels ): string {
		$parts = [];
		foreach ( $ids as $id ) {
			$label = trim( (string)( $labels[$id] ?? '' ) );
			$parts[] = self::linkText( $id, $label );
		}
		return implode( ', ', $parts );
	}

	/**
	 * A single `[https://www.openstreetmap.org/<id> <label>]` link. The
	 * label is sanitized as external-link TEXT (strip [ ] | { } < > — a
	 * Nominatim display name must never break the syntax or the template's
	 * table).
	 */
	private static function linkText( string $id, string $label ): string {
		$label = trim( (string)preg_replace( '/[\[\]|{}<>]/', '', $label ) );
		if ( $label === '' ) {
			$label = $id;
		}
		return '[https://www.openstreetmap.org/' . $id . ' ' . $label . ']';
	}
}
