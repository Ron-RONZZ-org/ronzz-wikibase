<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Fetch\ProviderResult;

/**
 * Special:AddCollective — create a non-person agent item (organization,
 * company, band, collective, institution) from Wikidata, issue #7.
 *
 * Class is inferred from the harvested instance-of hints (mapped through the
 * config), with a manual class picker fallback.
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddCollective extends SpecialAddExternalEntity {

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( 'AddCollective', $config, $client );
	}

	protected function kindKey(): string {
		return 'collective';
	}

	protected function buildSearchFields(): array {
		return [
			'name' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-name',
				'required' => true,
				'maxlength' => 250,
			],
		];
	}

	protected function search( array $data ): ProviderResult {
		$name = trim( (string)( $data['name'] ?? '' ) );
		if ( $name === '' ) {
			return new ProviderResult( [], [ 'No name given' ] );
		}
		return $this->client->searchEntities( $name );
	}

	/**
	 * Manual-form autofill (issue #35): the search `name` box becomes the
	 * manual `label` field.
	 *
	 * @param array<string,mixed> $search
	 * @return array<string,mixed>
	 */
	protected function autofillRecord( array $search ): array {
		$name = trim( (string)( $search['name'] ?? '' ) );
		return $name === '' ? parent::autofillRecord( $search ) : [ 'label' => $name ];
	}

	protected function candidateOptions( array $records ): array {
		return $this->candidateOptionLabels( $records );
	}

	protected function primaryLabel( array $record ): string {
		return (string)( $record['label'] ?? '' );
	}

	/** @return array<string,string> authority identifiers relevant to collectives */
	protected function externalIdRecordMap(): array {
		return [ 'wikidata' => 'wikidataId' ];
	}

	protected function harvest( string $qid ): ProviderResult {
		return $this->client->harvestEntity( $qid );
	}

	protected function reviewFieldSpecs( array $record ): array {
		return $this->labelFieldSpec( 'label', 'embeddablecontent-add-label', (string)( $record['label'] ?? '' ) )
			+ $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) )
			+ [
				// Optional parent organization (issue follow-up): an entity
				// combobox over existing items, writing the P749-aligned
				// statement. Filled but invalid ids are skipped (the same
				// lenient contract as the AddPerson place fields).
				'parentOrganization' => [
					'type' => 'combobox',
					'options' => [],
					'label-message' => 'embeddablecontent-field-parentorganization',
					'cssclass' => 'wb-entity-combobox',
					'default' => (string)( $record['parentOrganization'] ?? '' ),
					'help' => $this->msg( 'embeddablecontent-field-parentorganization-help' )->parse(),
				],
			]
			+ $this->externalIdFieldSpecs( $record );
	}

	/**
	 * Collective statements: the base authority/citation facts plus the
	 * optional parent organization entity link.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,\Wikibase\DataModel\DataValue|\Wikibase\DataModel\DataValue[]>
	 */
	protected function statementSpecs( array $record ): array {
		$specs = parent::statementSpecs( $record );
		$props = $this->config->collectivePropertyIds();
		$parent = trim( (string)( $record['parentOrganization'] ?? '' ) );
		if ( $parent !== '' && isset( $props['parentOrganization'] ) ) {
			$itemId = $this->parseItemId( $parent );
			if ( $itemId !== null ) {
				$specs[$props['parentOrganization']] = new \Wikibase\DataModel\Entity\EntityIdValue( $itemId );
			}
		}
		return $specs;
	}

	// ------------------------------------------------------------- classic page
	// The base afterCreate() writes a sitelinked Collective:<label> page
	// (the issue-#26 AddSoftware pattern); this class declares the page facts.

	protected function pageNamespace(): ?int {
		return defined( 'NS_COLLECTIVE' ) ? NS_COLLECTIVE : null;
	}

	protected function pageTemplate(): string {
		return 'Collective';
	}

	/**
	 * Collective: page skeleton — only sections with content are rendered
	 * (collectives currently fetch none, so the page is the template alone;
	 * the contributor adds sections by editing).
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageSkeleton( array $record, bool $withMarker = false ): string {
		$marker = $withMarker ? "\n<!-- " . $this->pagePendingMarker() . " -->\n" : "";
		return "{{Collective}}\n\n" . $marker;
	}

	protected function classOptions(): array {
		$options = [];
		foreach ( $this->config->agentClasses() as $key => $id ) {
			if ( $key !== 'person' ) {
				$options[$key] = $id;
			}
		}
		return $options;
	}

	protected function defaultClassItemId( array $record ): ?string {
		foreach ( $record['classWikidataIds'] ?? [] as $qid ) {
			$key = $this->config->agentClassByWikidata()[$qid] ?? null;
			if ( $key !== null && isset( $this->config->agentClasses()[$key] ) ) {
				return $this->config->agentClasses()[$key];
			}
		}
		return $this->config->agentClasses()['organization'] ?? null;
	}
}
