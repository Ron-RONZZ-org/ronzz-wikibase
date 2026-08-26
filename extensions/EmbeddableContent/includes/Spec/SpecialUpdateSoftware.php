<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use Wikibase\DataModel\Entity\Item;

/**
 * Special:UpdateSoftware — re-edit an existing FOSS software item with the
 * exact same review fields as Special:AddSoftware, prefilled from the
 * item's statements; submit UPDATES the item (and its FOSS: page on a
 * label change) instead of creating a new one.
 *
 * URL: Special:UpdateSoftware/Q42. The entity facts (developer, license,
 * operating system, …) are prefilled as comma-separated item ids; the
 * programming language maps back to its lexer key; the logo is preserved
 * unless a NEW logo is uploaded.
 *
 * @license GPL-2.0-or-later
 */
class SpecialUpdateSoftware extends SpecialAddSoftware {

	use UpdateExternalEntityFlow;

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( $config, $client, 'UpdateSoftware' );
	}

	protected function updateKindKey(): string {
		return 'software';
	}

	/**
	 * The include toggle says the logo is being REPLACED on update (the
	 * Add* wording "I will upload a logo image" implied a new entity).
	 */
	protected function logoIncludeMsgKey(): string {
		return 'embeddablecontent-update-software-logo-include';
	}

	protected function updateClassItemId( Item $item ): ?string {
		return $this->config->fossClasses()['foss'] ?? null;
	}

	protected function recordFromItem( Item $item ): array {
		$record = [
			'label' => $this->itemLabel( $item ),
			'description' => $this->itemDescription( $item ),
		];

		$props = $this->config->fossPropertyIds();
		$record['website'] = $this->firstStringForProperty( $item, $props['officialWebsite'] ?? null );
		$record['sourceRepository'] = $this->firstStringForProperty( $item, $props['sourceRepository'] ?? null );
		$record['documentationUrl'] = $this->firstStringForProperty( $item, $props['documentationUrl'] ?? null );
		foreach ( [ 'developer', 'license', 'operatingSystem', 'hasUse', 'replaces', 'userInterface' ] as $field ) {
			$record[$field] = implode( ', ', $this->entityIdsForProperty( $item, $props[$field] ?? null ) );
		}

		// Programming language: the statement value is a language ITEM id —
		// map it back to its lexer key (the combobox options are lexer keys).
		$languageItemId = $this->firstEntityForProperty( $item, $props['programmingLanguage'] ?? null );
		$record['programmingLanguage'] = $languageItemId !== ''
			? ( $this->config->lexerForItemId( $languageItemId ) ?? '' )
			: '';

		// Logo facts are NOT prefilled (the toggle defaults unchecked — the
		// existing logo is preserved).
		foreach ( [ 'logoInclude', 'logoMode', 'logoFile', 'logoUrl', 'logoExisting',
			'logoLicense', 'logoAuthor', 'logoLicenseInfo' ] as $key ) {
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
