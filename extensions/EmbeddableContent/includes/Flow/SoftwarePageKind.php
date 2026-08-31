<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use EmbeddableContent\EmbeddableContentConfig;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * The FOSS: vs Software: classic-page decision for software items — shared
 * by Special:AddSoftware (the page-kind radio default) and
 * action=addsemanticentity kind=software (the API page creation).
 *
 * A software whose chosen license qualifies as free/open-source documents
 * as a FOSS:<Name> page; anything else as a Software:<Name> page. The
 * license "qualifies" when its item is classified `instance of` the free
 * software license class (the preseed's foss-flagged licenses). Any FOSS
 * license among several wins; no license (or no FOSS-license class
 * configured on the instance) keeps the historical FOSS: default — the
 * caller may override (the form's radio, the API's pageKind param).
 *
 * @license GPL-2.0-or-later
 */
final class SoftwarePageKind {

	public const FOSS = 'foss';
	public const SOFTWARE = 'software';

	/**
	 * @param string $licenseInput comma-separated item ids (the form's
	 *  license combobox / the API's license field)
	 */
	public static function defaultFor( EmbeddableContentConfig $config, string $licenseInput ): string {
		$fossLicenseClass = $config->fossLicenseClasses()['fossLicense'] ?? null;
		if ( $fossLicenseClass === null ) {
			// No FOSS-license class configured: keep the historical FOSS:
			// default — the caller may override.
			return self::FOSS;
		}
		$ids = self::splitIds( $licenseInput );
		if ( $ids === [] ) {
			// No parseable license item ids: an empty license, or an
			// UNRESOLVED harvested label (Wikidata's "GNU General Public
			// License v3.0" vs the preseed's "GNU GPL-3.0" — the form shows
			// the label as context, the statement is only written once the
			// user picks a local item). Nothing to judge FOSS-ness from —
			// keep the historical FOSS: default.
			return self::FOSS;
		}
		foreach ( $ids as $id ) {
			try {
				$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $id ) );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( $item instanceof Item && self::itemHasClass( $item, $config, $fossLicenseClass ) ) {
				return self::FOSS;
			}
		}
		return self::SOFTWARE;
	}

	/** @return string[] validated item-id candidates (duplicates dropped) */
	private static function splitIds( string $input ): array {
		$out = [];
		foreach ( preg_split( '/[,;]\s*/u', trim( $input ) ) as $candidate ) {
			if ( preg_match( '/^Q[1-9]\d*$/i', $candidate ) === 1 ) {
				$id = strtoupper( $candidate );
				if ( !in_array( $id, $out, true ) ) {
					$out[] = $id;
				}
			}
		}
		return $out;
	}

	private static function itemHasClass( Item $item, EmbeddableContentConfig $config, string $classId ): bool {
		$instanceOf = $config->instanceOfPropertyId();
		foreach ( $item->getStatements() as $statement ) {
			$snak = $statement->getMainSnak();
			if ( !$snak instanceof PropertyValueSnak ) {
				continue;
			}
			if ( $snak->getPropertyId()->getSerialization() !== $instanceOf ) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$value = $value->getEntityId();
			}
			if ( $value instanceof ItemId && $value->getSerialization() === $classId ) {
				return true;
			}
		}
		return false;
	}
}
