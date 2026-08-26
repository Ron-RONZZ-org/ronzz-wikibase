<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use Wikibase\DataModel\Entity\Item;

/**
 * Special:UpdateCollective — re-edit an existing collective item (the agent
 * classes: organization, company, band, …) with the exact same review
 * fields as Special:AddCollective, prefilled from the item's statements;
 * submit UPDATES the item instead of creating a new one.
 *
 * URL: Special:UpdateCollective/Q42. The class is fixed on update (hidden
 * field — the item's existing agent class); the logo is preserved unless a
 * NEW logo is uploaded.
 *
 * @license GPL-2.0-or-later
 */
class SpecialUpdateCollective extends SpecialAddCollective {

	use UpdateExternalEntityFlow;

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( $config, $client, 'UpdateCollective' );
	}

	protected function updateKindKey(): string {
		return 'collective';
	}

	protected function updateClassItemId( Item $item ): ?string {
		$classIds = $this->itemClassIds( $item );
		foreach ( $this->config->agentClasses() as $key => $id ) {
			if ( $key === 'person' ) {
				continue; // a person is UpdatePerson's domain
			}
			if ( in_array( $id, $classIds, true ) ) {
				return $id;
			}
		}
		return null;
	}

	protected function recordFromItem( Item $item ): array {
		$record = [
			'label' => $this->itemLabel( $item ),
			'description' => $this->itemDescription( $item ),
			'parentOrganization' => $this->firstEntityForProperty(
				$item,
				$this->config->collectivePropertyIds()['parentOrganization'] ?? null
			),
		];
		// Logo facts are NOT prefilled (the toggle defaults unchecked — the
		// existing logo is preserved).
		foreach ( [ 'logoInclude', 'logoMode', 'logoFile', 'logoUrl',
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

	protected function baseManagedPropertyIds(): array {
		$ids = array_values( array_filter( $this->config->externalIdPropertyIds() ) );
		$ids = array_merge( $ids, array_values( array_filter( $this->config->citationMetadataPropertyIds() ) ) );
		$collective = $this->config->collectivePropertyIds();
		if ( isset( $collective['parentOrganization'] ) ) {
			$ids[] = $collective['parentOrganization'];
		}
		// The logo image facts (image/license/imageAuthor/imageLicenseInfo)
		// are NOT in the base set: replaced only when a NEW logo is uploaded.
		return array_values( array_unique( $ids ) );
	}
}
