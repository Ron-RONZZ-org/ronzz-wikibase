<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Content\ProvenanceWikitext;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;

/**
 * AddQuotation author inheritance: when `attributedTo` is blank and a
 * `source` is given, copy the source item's `attributed to` (P6) value.
 *
 * A source with exactly ONE author is inherited. A source with several
 * authors is NOT guessed — the caller asks the user to pick one. A source
 * with no author leaves the quotation author blank (the source itself is a
 * valid attribution), and a lookup failure is logged (never fatal).
 *
 * @license GPL-2.0-or-later
 */
final class QuotationSourceAuthor {

	/** The source has several authors; the user must choose one. */
	public const ERROR_MULTIPLE_AUTHORS = 'multiple_authors';

	/**
	 * @param array<string,mixed> $record the flow record (mutated in place)
	 * @param EntityLookup $lookup the local entity lookup
	 * @param string|null $authorPropertyId the `attributed to` property id
	 * @param callable(string,string):void|null $logWarning receives
	 *        (sourceId, errorMessage) on a lookup failure
	 * @return string|null null on success/no-op, or ERROR_MULTIPLE_AUTHORS
	 */
	public static function inherit(
		array &$record,
		EntityLookup $lookup,
		?string $authorPropertyId,
		?callable $logWarning = null
	): ?string {
		if ( trim( (string)( $record['attributedTo'] ?? '' ) ) !== '' ) {
			return null;
		}
		$source = trim( (string)( $record['source'] ?? '' ) );
		if ( $source === '' || preg_match( '/^Q[1-9]\d*$/', $source ) !== 1 ) {
			return null;
		}
		if ( $authorPropertyId === null || $authorPropertyId === '' ) {
			return null;
		}
		try {
			$item = $lookup->getEntity( new ItemId( $source ) );
		} catch ( \Throwable $e ) {
			if ( $logWarning !== null ) {
				$logWarning( $source, $e->getMessage() );
			}
			return null;
		}
		if ( !$item instanceof Item ) {
			return null;
		}
		$authors = array_values( array_unique(
			ProvenanceWikitext::entityIds( $item, $authorPropertyId )
		) );
		if ( $authors === [] ) {
			return null;
		}
		if ( count( $authors ) > 1 ) {
			return self::ERROR_MULTIPLE_AUTHORS;
		}
		$record['attributedTo'] = $authors[0];
		return null;
	}

}
