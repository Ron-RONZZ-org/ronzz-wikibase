<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use Wikibase\DataModel\Entity\Item;

/**
 * Special:UpdatePerson — re-edit an existing person item with the exact
 * same review fields as Special:AddPerson, prefilled from the item's
 * statements; submit UPDATES the item (label/description/statements)
 * instead of creating a new one.
 *
 * URL: Special:UpdatePerson/Q42. The label is the full name, re-derived
 * from given/family (NameSplitter — same convention as AddPerson); the
 * portrait is preserved unless a NEW portrait is uploaded.
 *
 * @license GPL-2.0-or-later
 */
class SpecialUpdatePerson extends SpecialAddPerson {

	use UpdateExternalEntityFlow;

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( $config, $client, 'UpdatePerson' );
	}

	protected function updateKindKey(): string {
		return 'person';
	}

	/**
	 * The include toggle says the portrait is being REPLACED on update (the
	 * Add* wording "I will upload a portrait image" implied a new entity).
	 */
	protected function portraitIncludeMsgKey(): string {
		return 'embeddablecontent-update-person-portrait-include';
	}

	protected function updateClassItemId( Item $item ): ?string {
		return $this->config->agentClasses()['person'] ?? null;
	}

	protected function recordFromItem( Item $item ): array {
		$record = [
			'description' => $this->itemDescription( $item ),
		];
		// The label is the full name — split it back into given/family (the
		// primaryLabel() re-derivation round-trips exactly).
		$split = NameSplitter::splitFullName( $this->itemLabel( $item ) );
		$record['givenName'] = $split['givenName'];
		$record['familyName'] = $split['familyName'];

		$props = $this->config->personPropertyIds();
		$record['dateOfBirth'] = $this->timeValueForProperty( $item, $props['dateOfBirth'] ?? null );
		$record['placeOfBirth'] = $this->firstEntityForProperty( $item, $props['placeOfBirth'] ?? null );
		$record['dateOfDeath'] = $this->timeValueForProperty( $item, $props['dateOfDeath'] ?? null );
		$record['placeOfDeath'] = $this->firstEntityForProperty( $item, $props['placeOfDeath'] ?? null );
		$record['deceased'] = $record['dateOfDeath'] !== '' || $record['placeOfDeath'] !== '';

		// Portrait facts are NOT prefilled into the upload section (the
		// toggle defaults unchecked — the existing portrait is preserved).
		foreach ( [ 'portraitInclude', 'portraitMode', 'portraitFile', 'portraitUrl', 'portraitExisting',
			'portraitLicense', 'portraitAuthor', 'portraitLicenseInfo' ] as $key ) {
			$record[$key] = '';
		}

		foreach ( $this->externalIdRecordMap() as $key => $field ) {
			$record[$field] = $this->firstStringForProperty(
				$item,
				$this->config->externalIdPropertyIds()[$key] ?? null
			);
		}
		return $record;
	}
}
