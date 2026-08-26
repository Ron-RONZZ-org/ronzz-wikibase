<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use Wikibase\DataModel\Entity\Item;

/**
 * Special:UpdateFictionalCharacter — re-edit an existing fictional-character
 * item with the exact same review fields as Special:AddFictionalCharacter,
 * prefilled from the item's statements; submit UPDATES the item instead of
 * creating a new one.
 *
 * URL: Special:UpdateFictionalCharacter/Q42. The label auto-generates as
 * "{given} {family} (fictional character)" (primaryLabel) — the given/family
 * fields split the existing label back (the suffix is stripped first).
 *
 * @license GPL-2.0-or-later
 */
class SpecialUpdateFictionalCharacter extends SpecialAddFictionalCharacter {

	use UpdateExternalEntityFlow;

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( $config, $client, 'UpdateFictionalCharacter' );
	}

	protected function updateKindKey(): string {
		return 'fictionalcharacter';
	}

	protected function updateClassItemId( Item $item ): ?string {
		return $this->config->fictionalCharacterClasses()['fictionalCharacter'] ?? null;
	}

	protected function recordFromItem( Item $item ): array {
		$label = $this->itemLabel( $item );
		// Strip the auto-generated "(fictional character)" suffix, then
		// split the remainder into given/family (primaryLabel round-trip).
		$name = trim( (string)preg_replace( '/\s*\(fictional character\)\s*$/i', '', $label ) );
		$split = NameSplitter::splitFullName( $name );
		$record = [
			'description' => $this->itemDescription( $item ),
			'givenName' => $split['givenName'],
			'familyName' => $split['familyName'],
			'appearsIn' => implode( ', ', $this->entityIdsForProperty(
				$item,
				$this->config->fictionalCharacterPropertyIds()['appearsIn'] ?? null
			) ),
		];
		foreach ( $this->externalIdRecordMap() as $key => $field ) {
			$record[$field] = $this->firstStringForProperty(
				$item,
				$this->config->externalIdPropertyIds()[$key] ?? null
			);
		}
		return $record;
	}
}
