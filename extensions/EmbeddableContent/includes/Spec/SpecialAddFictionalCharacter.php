<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Fetch\ProviderResult;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Special:AddFictionalCharacter — create a fictional-character item from
 * Wikidata (search → select → review → create, manual fallback).
 *
 * The label auto-generates as "{given name} {family name} (fictional
 * character)" from the given/family names (harvested via P735/P734, or
 * split from the search name); the description auto-generates as "fictional
 * character in {…}" from the appears-in works when left blank. The
 * appears-in works (P1441, multi-value) are an entity combobox referencing
 * existing local work items.
 *
 * Item-only: no classic wiki page.
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddFictionalCharacter extends SpecialAddExternalEntity {

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( 'AddFictionalCharacter', $config, $client );
	}

	protected function kindKey(): string {
		return 'fictionalcharacter';
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
		// Wikidata only (the entity hub): the OpenAlex/dblp/ORCID person
		// providers are for real people, not characters.
		return $this->client->searchEntities( $name );
	}

	/**
	 * Manual-form autofill: the search `name` box becomes given/family
	 * (every word except the last = given, last word = family).
	 *
	 * @param array<string,mixed> $search
	 * @return array<string,mixed>
	 */
	protected function autofillRecord( array $search ): array {
		$name = trim( (string)( $search['name'] ?? '' ) );
		return $name === '' ? parent::autofillRecord( $search ) : NameSplitter::splitFullName( $name );
	}

	protected function candidateOptions( array $records ): array {
		return $this->candidateOptionLabels( $records );
	}

	protected function primaryLabel( array $record ): string {
		$given = trim( (string)( $record['givenName'] ?? '' ) );
		$family = trim( (string)( $record['familyName'] ?? '' ) );
		if ( $given !== '' || $family !== '' ) {
			return $this->msg( 'embeddablecontent-fictionalcharacter-label', trim( $given . ' ' . $family ) )->text();
		}
		// A harvested label-only candidate (no name parts) keeps its label.
		return (string)( $record['label'] ?? '' );
	}

	/** @return array<string,string> authority identifiers relevant to characters */
	protected function externalIdRecordMap(): array {
		return [ 'wikidata' => 'wikidataId' ];
	}

	protected function harvest( string $qid ): ProviderResult {
		return $this->client->harvestPerson( $qid );
	}

	protected function reviewFieldSpecs( array $record ): array {
		$appearsIn = $record['appearsInIds'] ?? $record['appearsIn'] ?? '';
		$default = is_array( $appearsIn ) ? implode( ', ', $appearsIn ) : (string)$appearsIn;
		// NO editable label field: the label is auto-generated from
		// given/family + the "(fictional character)" suffix (primaryLabel).
		return $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) )
			+ [
				'givenName' => $this->plainTextField( 'embeddablecontent-field-givenname', (string)( $record['givenName'] ?? '' ) ),
				'familyName' => $this->plainTextField( 'embeddablecontent-field-familyname', (string)( $record['familyName'] ?? '' ) ),
				'appearsIn' => [
					'type' => 'combobox',
					'options' => [],
					'label-message' => 'embeddablecontent-fictionalcharacter-field-appearsin',
					'cssclass' => 'wb-entity-combobox wb-entity-combobox-multi',
					'default' => $default,
					'help' => $this->msg( 'embeddablecontent-fictionalcharacter-field-appearsin-help' )->parse(),
				],
			]
			+ $this->externalIdFieldSpecs( $record );
	}

	/**
	 * Description autogen: "fictional character in {work, work}" from the
	 * appears-in items' labels when the description is left blank.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function beforeCreate( array &$record ): ?string {
		if ( trim( (string)( $record['description'] ?? '' ) ) !== '' ) {
			return null;
		}
		$labels = [];
		foreach ( ItemIdList::split( (string)( $record['appearsIn'] ?? '' ) ) as $id ) {
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $id ) );
			if ( !$item instanceof Item ) {
				continue;
			}
			$term = $item->getLabels()->getByLanguage( 'en' );
			if ( $term !== null ) {
				$labels[] = $term->getText();
			}
		}
		if ( $labels !== [] ) {
			$record['description'] = $this->msg(
				'embeddablecontent-fictionalcharacter-desc-autogen',
				implode( ', ', $labels )
			)->text();
		}
		return null;
	}

	/**
	 * Character statement specs: the base authority/citation facts (incl.
	 * the given/family names) plus one `present in work` entity statement
	 * per appears-in work.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,\Wikibase\DataModel\DataValue|\Wikibase\DataModel\DataValue[]>
	 */
	protected function statementSpecs( array $record ): array {
		$specs = parent::statementSpecs( $record );
		$props = $this->config->fictionalCharacterPropertyIds();
		if ( isset( $props['appearsIn'] ) ) {
			foreach ( ItemIdList::split( (string)( $record['appearsIn'] ?? '' ) ) as $id ) {
				if ( preg_match( '/^Q[1-9]\d*$/', $id ) === 1 ) {
					$specs[$props['appearsIn']][] = new EntityIdValue( new ItemId( $id ) );
				}
			}
		}
		return $specs;
	}

	protected function classOptions(): array {
		return $this->config->fictionalCharacterClasses();
	}

	protected function defaultClassItemId( array $record ): ?string {
		return $this->config->fictionalCharacterClasses()['fictionalCharacter'] ?? null;
	}
}
