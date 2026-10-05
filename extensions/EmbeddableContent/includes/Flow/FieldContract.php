<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

/**
 * The canonical machine-readable field contract of the entity-mode Add* flows.
 *
 * One emitter aggregates the three per-flow field maps
 * (SpecialContentFieldMap, SourceFieldMap, SemanticEntityFieldMap) — the
 * single authoring source — into a versioned JSON document consumed by
 * downstream clients (the MediaWiki MCP server generates its tool input
 * schemas from it). Because the JSON is a projection of the maps, a field
 * added to a map without re-emitting is caught by FieldContractTest, and a
 * client that pins the JSON cannot silently drift from the wiki.
 *
 * Pure PHP (no MediaWiki runtime) — the maps carry no MW dependency, so the
 * contract can be emitted and reviewed anywhere.
 *
 * @license GPL-2.0-or-later
 */
final class FieldContract {

	/** Bumped when the document's SHAPE changes (not when a field is added). */
	public const VERSION = 1;

	/**
	 * @return array<string,mixed>
	 */
	public static function toArray(): array {
		return [
			'version' => self::VERSION,
			'generator' => 'EmbeddableContent maintenance/emitFieldContract.php',
			'flows' => [
				'special-content' => [
					'kinds' => self::entries(
						SpecialContentFieldMap::KINDS,
						[ SpecialContentFieldMap::class, 'fieldsForKind' ],
						[ SpecialContentFieldMap::class, 'requiredOnCreate' ]
					),
				],
				'citation-source' => [
					'classes' => self::entries(
						SourceFieldMap::CLASS_KEYS,
						[ SourceFieldMap::class, 'fieldsForClass' ],
						[ SourceFieldMap::class, 'requiredOnCreate' ]
					),
				],
				'semantic-entity' => [
					'kinds' => self::entries(
						SemanticEntityFieldMap::KINDS,
						[ SemanticEntityFieldMap::class, 'fieldsForKind' ],
						[ SemanticEntityFieldMap::class, 'requiredOnCreate' ]
					),
				],
			],
		];
	}

	/**
	 * The exact bytes of the committed contract file.
	 */
	public static function toJson(): string {
		return json_encode( self::toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/**
	 * @param string[] $keys
	 * @param callable(string): string[] $fields
	 * @param callable(string): string[] $required
	 * @return array<string,array{fields:string[],requiredOnCreate:string[]}>
	 */
	private static function entries( array $keys, callable $fields, callable $required ): array {
		$out = [];
		foreach ( $keys as $key ) {
			$out[$key] = [
				'fields' => array_values( $fields( $key ) ),
				'requiredOnCreate' => array_values( $required( $key ) ),
			];
		}
		return $out;
	}
}
