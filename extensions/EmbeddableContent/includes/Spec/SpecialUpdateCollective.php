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
 * URL: Special:UpdateCollective/Q42. The class is editable (the item's
 * existing agent class is preselected; a changed value replaces the
 * `instance of` statement); the logo is preserved unless a NEW logo is
 * uploaded.
 *
 * @license GPL-2.0-or-later
 */
class SpecialUpdateCollective extends SpecialAddCollective {
	/**
	 * The no-clobber managed set + replacement values come from the shared
	 * SemanticEntityFlowService (the action=addsemanticentity contract).
	 */
	protected function updateStatementSpecs( array $record ): array {
		return $this->semanticFlow()->statementSpecs(
			'collective',
			$this->semanticFlowRecord( 'collective', $record )
		);
	}


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

	/**
	 * The include toggle says the logo is being REPLACED on update (the
	 * Add* wording "I will upload a logo image" implied a new entity).
	 */
	protected function logoIncludeMsgKey(): string {
		return 'embeddablecontent-update-collective-logo-include';
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

	/**
	 * A collective may be reclassified on update — unlike the other Update*
	 * pages, whose class is fixed. The submitted value rides the record via
	 * withChosenClass() and replaces the item's instance-of statement.
	 *
	 * @return array<string,mixed>
	 */
	protected function updateClassFieldSpec( string $classItemId ): array {
		return [ 'class' => [
			'type' => 'select',
			'label-message' => 'embeddablecontent-extselect-class',
			'options' => $this->classOptions(),
			'default' => $classItemId,
			'required' => true,
		] ];
	}

	protected function recordFromItem( Item $item ): array {
		$record = [
			'label' => $this->itemLabel( $item ),
			'description' => $this->itemDescription( $item ),
			'labelLanguage' => $this->itemTermLanguage( $item ),
			'parentOrganization' => implode(
				', ',
				$this->entityIdsForProperty(
					$item,
					$this->config->collectivePropertyIds()['parentOrganization'] ?? null
				)
			),
		];
		$props = $this->config->collectivePropertyIds();
		$record['imageEntries'] = \EmbeddableContent\Spec\MediaEntries::fromItem( $item, $props['image'] ?? '' );
		$record['website'] = $this->firstStringForProperty( $item, $props['officialWebsite'] ?? null );
		// Group-of-humans membership: prefill the multi-entity list so
		// existing members can be removed and new ones added.
		$groupClass = $this->config->agentClasses()['groupOfHumans'] ?? null;
		if ( $groupClass !== null && in_array( $groupClass, $this->itemClassIds( $item ), true ) ) {
			$record['members'] = implode(
				', ',
				$this->entityIdsForProperty( $item, $props['hasMember'] ?? null )
			);
			$record['memberNames'] = '';
			$record['memberChoices'] = '';
		}
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
