<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Lib\TermIndexEntry;
use Wikibase\Repo\WikibaseRepo;
use Wikimedia\Rdbms\DBError;

/**
 * The local item whose label matches exactly (case-insensitive), or null.
 *
 * The one exact-label lookup shared by the Add* create-or-skip path and the
 * collision guard (the AddCollective Q2655 report: the class-filtered
 * duplicate matcher cannot see an unclassified item, but this lookup can).
 *
 * @license GPL-2.0-or-later
 */
final class ItemLabelLookup {

	/**
	 * @param string $label the label to match (case-insensitive)
	 * @param string $language the term language (the item's own label language)
	 * @return string|null the item id serialization, or null when none matches
	 */
	public static function findIdByLabel( string $label, string $language = 'en' ): ?string {
		$label = trim( $label );
		if ( $label === '' ) {
			return null;
		}
		try {
			$entries = WikibaseRepo::getMatchingTermsLookupFactory()
				->getLookupForSource( WikibaseRepo::getLocalEntitySource() )
				->getMatchingTerms(
					$label,
					Item::ENTITY_TYPE,
					$language,
					TermIndexEntry::TYPE_LABEL,
					[ 'caseSensitive' => false ]
				);
		} catch ( DBError $e ) {
			return null;
		}
		foreach ( $entries as $entry ) {
			$id = $entry->getEntityId();
			if ( $id instanceof ItemId ) {
				return $id->getSerialization();
			}
		}
		return null;
	}

}
