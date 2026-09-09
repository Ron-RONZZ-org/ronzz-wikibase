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
	/**
	 * The no-clobber managed set + replacement values come from the shared
	 * SemanticEntityFlowService (the action=addsemanticentity contract).
	 */
	protected function updateStatementSpecs( array $record ): array {
		return $this->semanticFlow()->statementSpecs(
			'person',
			$this->semanticFlowRecord( 'person', $record )
		);
	}


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

	/**
	 * Post-update label hygiene for the OSM place fields: the no-clobber
	 * contract replaces a statement only when the form provides a NEW
	 * non-empty value — but a place label must never survive its OSM id.
	 * When the submitted OSM id CHANGED (or was cleared) relative to the
	 * pre-update item and no new label was submitted, remove the stored
	 * label statement so the Person: page never shows a stale display name
	 * for a different place (a newly picked place always carries a fresh
	 * label from osmsuggest).
	 *
	 * Runs only when a label was actually removed — an update that changed
	 * nothing else writes no extra revision.
	 *
	 * @param \Wikibase\DataModel\Entity\Item $item the just-updated item
	 * @param array<string,mixed> $record the update record
	 * @param string[] $oldParents unused for persons (no child-items rows)
	 * @param array<string,string> $oldOsmPlaces the OSM place ids BEFORE the update
	 */
	protected function afterUpdate( \Wikibase\DataModel\Entity\Item $item, array $record, array $oldParents, array $oldOsmPlaces ): void {
		$props = $this->config->personPropertyIds();
		$dirty = false;
		foreach ( [
			'placeOfBirthOsm' => [ 'placeOfBirthLabel', 'placeOfBirthOsmLabel' ],
			'placeOfDeathOsm' => [ 'placeOfDeathLabel', 'placeOfDeathOsmLabel' ],
		] as $osmField => [ $labelPropKey, $labelFormKey ] ) {
			$labelProp = $props[$labelPropKey] ?? null;
			$submittedOsm = trim( (string)( $record[$osmField] ?? '' ) );
			$submittedLabel = trim( (string)( $record[$labelFormKey] ?? '' ) );
			if ( $labelProp === null || $submittedLabel !== '' ) {
				continue;
			}
			$oldOsm = trim( (string)( $oldOsmPlaces[$osmField] ?? '' ) );
			if ( $oldOsm !== '' && $submittedOsm !== '' && $oldOsm !== $submittedOsm ) {
				// The OSM id was REPLACED by a different one without a new
				// label — drop any stored label so it cannot describe the
				// old place. A BLANKED field is not removal (no-clobber:
				// both the id and its label survive); a fresh pick always
				// carries its own label from osmsuggest.
				foreach ( $item->getStatements()->getByPropertyId(
					new \Wikibase\DataModel\Entity\NumericPropertyId( $labelProp )
				) as $statement ) {
					$guid = $statement->getGuid();
					if ( $guid !== null ) {
						$item->getStatements()->removeStatementsWithGuid( $guid );
						$dirty = true;
					}
				}
			}
		}
		if ( $dirty ) {
			try {
				WikibaseRepo::getEntityStore()->saveEntity(
					$item,
					$this->msg( 'embeddablecontent-update-edit-summary', $this->itemLabel( $item ) )
						->inContentLanguage()->text(),
					$this->getUser(),
					EDIT_UPDATE
				);
			} catch ( \Throwable $e ) {
				// Best-effort label hygiene: the item update itself already
				// saved; a failed tidy only leaves a stale label behind.
			}
		}
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
		$record['placeOfBirthOsm'] = $this->firstStringForProperty( $item, $props['placeOfBirthOsm'] ?? null );
		$record['dateOfDeath'] = $this->timeValueForProperty( $item, $props['dateOfDeath'] ?? null );
		$record['placeOfDeathOsm'] = $this->firstStringForProperty( $item, $props['placeOfDeathOsm'] ?? null );
		// The parallel human-readable place labels (osm-places follow-up):
		// prefilled so an update keeps them (they are managed statements —
		// a blank keeps the stored value).
		$record['placeOfBirthOsmLabel'] = $this->firstStringForProperty(
			$item, $props['placeOfBirthLabel'] ?? null
		);
		$record['placeOfDeathOsmLabel'] = $this->firstStringForProperty(
			$item, $props['placeOfDeathLabel'] ?? null
		);
		$record['deceased'] = $record['dateOfDeath'] !== '' || $record['placeOfDeathOsm'] !== '';
		$record['website'] = $this->firstStringForProperty( $item, $props['officialWebsite'] ?? null );

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
