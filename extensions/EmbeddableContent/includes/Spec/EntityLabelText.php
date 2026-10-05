<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use Wikibase\DataModel\Entity\Item;

/**
 * Reads an item's label text with a language fallback.
 *
 * `TermList::getByLanguage()` THROWS `OutOfBoundsException` when the term is
 * absent (it does not return null), so every label read must guard first.
 * The AddSource `language` field stores the label under the item's own term
 * language — a French source carries only an `fr` label — so the naive
 * `getByLanguage( 'en' )` that used to be safe now 500s the classic-page
 * render and the book-excerpt/fictional-character auto-description.
 *
 * Order: the preferred language, then English, then the first available
 * label; null when the item has no label at all.
 *
 * @license GPL-2.0-or-later
 */
final class EntityLabelText {

	public static function of( Item $item, string $preferred = 'en' ): ?string {
		$labels = $item->getLabels();
		if ( $labels->hasTermForLanguage( $preferred ) ) {
			return $labels->getByLanguage( $preferred )->getText();
		}
		if ( $preferred !== 'en' && $labels->hasTermForLanguage( 'en' ) ) {
			return $labels->getByLanguage( 'en' )->getText();
		}
		$texts = $labels->toTextArray();
		return $texts === [] ? null : (string)reset( $texts );
	}
}
