<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\DecimalValue;
use DataValues\QuantityValue;
use DataValues\StringValue;
use DataValues\TimeValue;
use DataValues\DataValue;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\Repo\WikibaseRepo;

/**
 * The "additional semantic statements" cloner's server side.
 *
 * The Add* / Update* forms can carry a list of extra statements the form has
 * no dedicated field for (occupation → Q123, a string identifier, a number,
 * a date, …). The cloner submits rows of {property, value}; this helper
 * validates them against the property datatype, builds DataValues, prefills
 * an Update form with the item's non-managed supported statements, and
 * applies the submit as a DIFF (unchanged statements keep their GUID,
 * qualifiers and references).
 *
 * Supported datatypes: wikibase-item, string, external-id, url, quantity,
 * time. Other datatypes are left untouched and are not offered in the form.
 *
 * @license GPL-2.0-or-later
 */
final class AdditionalStatements {

	/** Datatypes the cloner can read/write. */
	public const SUPPORTED_DATATYPES = [
		'wikibase-item', 'string', 'external-id', 'url', 'quantity', 'time',
	];

	/**
	 * @param array<int,array{property?:string,value?:string}> $rows
	 * @param string[] $managedPropertyIds properties the form owns
	 * @return array{values: array<string,list<DataValue>>, error: ?string}
	 */
	public static function dataValues( array $rows, array $managedPropertyIds = [] ): array {
		$values = [];
		$managed = array_fill_keys( $managedPropertyIds, true );
		foreach ( $rows as $row ) {
			if ( !is_array( $row ) ) {
				continue;
			}
			$property = strtoupper( trim( (string)( $row['property'] ?? '' ) ) );
			$value = trim( (string)( $row['value'] ?? '' ) );
			if ( $property === '' && $value === '' ) {
				continue; // empty cloner row
			}
			if ( preg_match( '/^P[1-9]\d*$/', $property ) !== 1 ) {
				return [ 'values' => [], 'error' => "additional statement property \"{$property}\" is not a property ID (P<n>)." ];
			}
			if ( isset( $managed[$property] ) ) {
				return [
					'values' => [],
					'error' => "additional statement property {$property} is managed by the form; use its dedicated field.",
				];
			}
			$datatype = self::datatype( $property );
			if ( $datatype === null ) {
				return [ 'values' => [], 'error' => "property {$property} does not exist." ];
			}
			if ( !in_array( $datatype, self::SUPPORTED_DATATYPES, true ) ) {
				return [
					'values' => [],
					'error' => "property {$property} has unsupported datatype {$datatype}; supported: "
						. implode( ', ', self::SUPPORTED_DATATYPES ) . '.',
				];
			}
			$dataValue = self::dataValue( $datatype, $value );
			if ( $dataValue === null ) {
				return [ 'values' => [], 'error' => "value \"{$value}\" is not valid for {$property} ({$datatype})." ];
			}
			$values[$property][] = $dataValue;
		}
		return [ 'values' => $values, 'error' => null ];
	}

	/**
	 * Prefills the cloner with the item's non-managed, supported statements.
	 *
	 * @param string[] $managedPropertyIds
	 * @return list<array{property:string,value:string}>
	 */
	public static function rowsForItem( Item $item, array $managedPropertyIds ): array {
		$managed = array_fill_keys( $managedPropertyIds, true );
		$rows = [];
		foreach ( $item->getStatements() as $statement ) {
			$property = $statement->getPropertyId()->getSerialization();
			if ( isset( $managed[$property] ) ) {
				continue;
			}
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak ) {
				continue;
			}
			$value = self::serializeValue( $snak->getDataValue() );
			if ( $value === null ) {
				continue;
			}
			$rows[] = [ 'property' => $property, 'value' => $value ];
		}
		return $rows;
	}

	/**
	 * Diffs the submitted additional rows into the item: an unchanged
	 * (property, value) pair keeps its existing statement; a removed pair is
	 * deleted; a new pair is added. Only supported datatypes and properties
	 * NOT in `$managedPropertyIds` are touched — everything else is preserved.
	 *
	 * @param array<int,array{property?:string,value?:string}> $rows
	 * @param string[] $managedPropertyIds
	 */
	public static function applyDiff( Item $item, array $rows, array $managedPropertyIds ): ?string {
		$built = self::dataValues( $rows, $managedPropertyIds );
		if ( $built['error'] !== null ) {
			return $built['error'];
		}
		$desired = [];
		foreach ( $built['values'] as $property => $values ) {
			foreach ( $values as $value ) {
				$serialized = self::serializeValue( $value );
				if ( $serialized !== null ) {
					$desired[$property . '|' . $serialized] = [ $property, $value ];
				}
			}
		}
		$used = [];
		$remove = [];
		$managed = array_fill_keys( $managedPropertyIds, true );
		foreach ( $item->getStatements() as $statement ) {
			$property = $statement->getPropertyId()->getSerialization();
			if ( isset( $managed[$property] ) || self::datatype( $property ) === null ) {
				continue;
			}
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak ) {
				continue;
			}
			$serialized = self::serializeValue( $snak->getDataValue() );
			if ( $serialized === null ) {
				continue;
			}
			$key = $property . '|' . $serialized;
			if ( isset( $desired[$key] ) ) {
				$used[$key] = true;
				continue;
			}
			$guid = $statement->getGuid();
			if ( $guid !== null ) {
				$remove[] = $guid;
			}
		}
		foreach ( $remove as $guid ) {
			$item->getStatements()->removeStatementsWithGuid( $guid );
		}
		$guidGenerator = new GuidGenerator();
		foreach ( $desired as $key => [ $property, $value ] ) {
			if ( isset( $used[$key] ) ) {
				continue;
			}
			$item->getStatements()->addStatement( new Statement(
				new PropertyValueSnak( new NumericPropertyId( $property ), $value ),
				null,
				null,
				$guidGenerator->newGuid( $item->getId() )
			) );
		}
		return null;
	}

	/** The datatype of a property, or null when it does not exist. */
	public static function datatype( string $propertyId ): ?string {
		try {
			$info = WikibaseRepo::getPropertyInfoLookup()->getPropertyInfo(
				new NumericPropertyId( $propertyId )
			);
		} catch ( \Throwable $e ) {
			return null;
		}
		return $info?->getDataTypeId();
	}

	private static function dataValue( string $datatype, string $value ): ?DataValue {
		switch ( $datatype ) {
			case 'wikibase-item':
				if ( preg_match( '/^Q[1-9]\d*$/', $value ) !== 1 ) {
					return null;
				}
				try {
					$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $value ) );
				} catch ( \Throwable $e ) {
					return null;
				}
				return $item instanceof Item ? new EntityIdValue( new ItemId( $value ) ) : null;
			case 'string':
			case 'external-id':
				return $value === '' ? null : new StringValue( $value );
			case 'url':
				if ( preg_match( '#^https?://#i', $value ) !== 1 ) {
					return null;
				}
				return new StringValue( $value );
			case 'quantity':
				if ( !is_numeric( $value ) ) {
					return null;
				}
				$amount = new DecimalValue( $value );
				return new QuantityValue( $amount, $amount, $amount, '1' );
			case 'time':
				$parts = WikibaseTime::parts( $value );
				if ( $parts === null ) {
					return null;
				}
				$sign = $parts['negative'] ? '-' : '+';
				$calendar = $parts['negative']
					? 'http://www.wikidata.org/entity/Q1985786'
					: 'http://www.wikidata.org/entity/Q1985727';
				return new TimeValue(
					sprintf( '%s%04d-%02d-%02dT00:00:00Z', $sign, $parts['year'], $parts['month'], $parts['day'] ),
					0, 0, 0, TimeValue::PRECISION_DAY, $calendar
				);
			default:
				return null;
		}
	}

	/** A canonical display/diff string for a supported DataValue, or null. */
	public static function serializeValue( DataValue $value ): ?string {
		if ( $value instanceof EntityIdValue ) {
			return $value->getEntityId()->getSerialization();
		}
		if ( $value instanceof StringValue ) {
			return (string)$value->getValue();
		}
		if ( $value instanceof QuantityValue ) {
			return $value->getAmount()->getValue();
		}
		if ( $value instanceof TimeValue ) {
			return WikibaseTime::isoDay( $value->getTime() ) ?? $value->getTime();
		}
		return null;
	}

}
