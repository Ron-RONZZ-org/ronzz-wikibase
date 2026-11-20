<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use Wikibase\DataModel\Entity\Item;
use EmbeddableContent\PropertyIdFactory;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;

/**
 * A form-open snapshot of the managed statement properties, used by the
 * Update* pages to decide whether a BLANKED field may remove its statements.
 *
 * The Update form is prefilled from the item, so a blank field means “the user
 * cleared it”. But “blank” alone is not enough: another editor may have
 * changed the statement between the page load and the submit, or the property
 * may never have had a statement. This class records the GUIDs + value hashes
 * of every managed property at page-open, and on submit only reports a
 * property for removal when the field was rendered blank AND the item's current
 * statements still match that snapshot. A concurrent edit therefore keeps the
 * newer value instead of being silently deleted.
 *
 * The snapshot rides a hidden form field as JSON; it is not a secret (the same
 * user can edit the item page directly), but malformed/unknown rows are ignored.
 *
 * @license GPL-2.0-or-later
 */
final class StatementSnapshot {

	/** The hidden form field name (HTMLForm prefixes it with `wp`). */
	public const FIELD = 'updateSnapshot';

	/**
	 * Snapshot rows for the item's currently-populated properties.
	 *
	 * @param array<string,string|string[]> $fieldProperties
	 *   form field name => property id(s) that field owns
	 * @return list<array{field:string,property:string,guids:string[],hashes:string[]}>
	 */
	public static function rowsFor( Item $item, array $fieldProperties ): array {
		$rows = [];
		$seen = [];
		foreach ( $fieldProperties as $field => $propertyIds ) {
			foreach ( (array)$propertyIds as $propertyId ) {
				$propertyId = (string)$propertyId;
				$key = $field . "\0" . $propertyId;
				if ( $propertyId === '' || isset( $seen[$key] ) ) {
					continue;
				}
				$seen[$key] = true;
				$statements = $item->getStatements()
					->getByPropertyId( PropertyIdFactory::create( $propertyId ) );
				if ( count( $statements ) === 0 ) {
					continue;
				}
				$guids = [];
				$hashes = [];
				foreach ( $statements as $statement ) {
					$guids[] = (string)$statement->getGuid();
					$hashes[] = self::valueHash( $statement );
				}
				sort( $guids );
				sort( $hashes );
				$rows[] = [
					'field' => (string)$field,
					'property' => $propertyId,
					'guids' => $guids,
					'hashes' => $hashes,
				];
			}
		}
		return $rows;
	}

	/**
	 * Whether the item's CURRENT statements for the row's property are still
	 * the ones captured at page-open.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function matches( Item $item, array $row ): bool {
		$propertyId = (string)( $row['property'] ?? '' );
		if ( $propertyId === '' ) {
			return false;
		}
		$statements = $item->getStatements()
			->getByPropertyId( PropertyIdFactory::create( $propertyId ) );
		if ( count( $statements ) === 0 ) {
			return false;
		}
		$guids = [];
		$hashes = [];
		foreach ( $statements as $statement ) {
			$guids[] = (string)$statement->getGuid();
			$hashes[] = self::valueHash( $statement );
		}
		sort( $guids );
		sort( $hashes );
		$expectedGuids = array_map( 'strval', (array)( $row['guids'] ?? [] ) );
		$expectedHashes = array_map( 'strval', (array)( $row['hashes'] ?? [] ) );
		sort( $expectedGuids );
		sort( $expectedHashes );
		return $guids === $expectedGuids && $hashes === $expectedHashes;
	}

	/**
	 * The property ids that may be removed: the field was rendered blank and
	 * the current statements still match the snapshot.
	 *
	 * @param list<array<string,mixed>> $rows
	 * @param callable(string):bool $isBlank
	 * @return string[]
	 */
	public static function clearProperties( Item $item, array $rows, callable $isBlank ): array {
		$clear = [];
		foreach ( $rows as $row ) {
			if ( !is_array( $row ) ) {
				continue;
			}
			$field = (string)( $row['field'] ?? '' );
			if ( $field === '' || !$isBlank( $field ) ) {
				continue;
			}
			if ( self::matches( $item, $row ) ) {
				$clear[] = (string)$row['property'];
			}
		}
		return array_values( array_unique( $clear ) );
	}

	/** Decodes the hidden field, dropping malformed rows. */
	public static function decode( string $json ): array {
		$data = json_decode( $json, true );
		if ( !is_array( $data ) ) {
			return [];
		}
		$rows = [];
		foreach ( $data as $row ) {
			if ( !is_array( $row )
				|| !isset( $row['field'], $row['property'] )
				|| !is_array( $row['guids'] ?? null )
				|| !is_array( $row['hashes'] ?? null )
			) {
				continue;
			}
			$rows[] = [
				'field' => (string)$row['field'],
				'property' => (string)$row['property'],
				'guids' => array_map( 'strval', $row['guids'] ),
				'hashes' => array_map( 'strval', $row['hashes'] ),
			];
		}
		return $rows;
	}

	private static function valueHash( Statement $statement ): string {
		$snak = $statement->getMainSnak();
		if ( $snak instanceof PropertyValueSnak ) {
			$value = $snak->getDataValue();
			return md5( (string)json_encode( $value->getArrayValue() ) );
		}
		return md5( $snak->getHash() );
	}

}
