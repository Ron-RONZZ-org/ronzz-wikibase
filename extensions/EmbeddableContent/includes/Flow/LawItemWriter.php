<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use DataValues\MonolingualTextValue;
use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\Content\PayloadCodec;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\PropertyIdFactory;
use EmbeddableContent\Search\EntityTermSearch;
use MediaWiki\User\UserIdentity;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\Repo\WikibaseRepo;

/**
 * Creates the entity-only law and legal-provision items for the corpus.
 *
 * There is deliberately no ClassicPageCreator call: legal items carry no
 * Source: page (the human view is Special:Law). The item is the citable
 * entity; Special:Law/<law_key>/<article> is its readable page.
 *
 * @license GPL-2.0-or-later
 */
final class LawItemWriter {

	private EmbeddableContentConfig $config;

	private EntityTermSearch $termSearch;

	public function __construct( EmbeddableContentConfig $config, EntityTermSearch $termSearch ) {
		$this->config = $config;
		$this->termSearch = $termSearch;
	}

	/**
	 * @param array<string,mixed> $law a wb_law_laws row
	 * @return string|null the item id, or null when the item cannot be created
	 */
	public function ensureLawItem( array $law, UserIdentity $user ): ?string {
		$existing = trim( (string)( $law['item_id'] ?? '' ) );
		if ( $existing !== '' ) {
			return $existing;
		}
		$label = trim( (string)( $law['title'] ?? '' ) );
		$class = $this->config->sourceClasses()['legislation'] ?? null;
		if ( $label === '' || $class === null ) {
			return null;
		}
		$found = $this->findItemByLabel( $label, $class )
			?? $this->findItemByLabel( $label . ' (Legislation)', $class );
		if ( $found !== null ) {
			$this->ensureLegislationNumber( $found, (string)$law['law_key'], $user );
			return $found;
		}

		$item = new Item();
		$item->setLabel( 'fr', $label );
		$item->setDescription( 'fr', 'texte législatif français' );
		$item->setDescription( 'en', 'French legislative text' );
		$item->getStatements()->addStatement(
			$this->statement( $this->config->instanceOfPropertyId(), new EntityIdValue( new ItemId( $class ) ) )
		);
		$legislationNumber = $this->config->sourcePropertyIds()['legislationNumber'] ?? null;
		if ( $legislationNumber !== null ) {
			$item->getStatements()->addStatement(
				$this->statement( $legislationNumber, new StringValue( (string)$law['law_key'] ) )
			);
		}
		$this->addJurisdiction( $item );
		$date = $this->config->provenancePropertyIds()['date'] ?? null;
		$start = (string)( $law['version_start'] ?? '' );
		if ( $date !== null && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) === 1 ) {
			$item->getStatements()->addStatement( $this->statement( $date, $this->dayValue( $start ) ) );
		}
		$url = $this->config->sourcePropertyIds()['url'] ?? null;
		if ( $url !== null && !empty( $law['source_url'] ) ) {
			$item->getStatements()->addStatement(
				$this->statement( $url, new StringValue( (string)$law['source_url'] ) )
			);
		}
		return $this->save( $item, 'law corpus: create legislation item', $user );
	}

	/**
	 * @param array<string,mixed> $law a wb_law_laws row
	 * @param array<string,mixed> $article a wb_law_articles row
	 * @return string|null the item id, or null when the item cannot be created
	 */
	public function ensureProvisionItem( array $law, array $article, string $lawItemId, UserIdentity $user ): ?string {
		$existing = trim( (string)( $article['item_id'] ?? '' ) );
		if ( $existing !== '' ) {
			return $existing;
		}
		$class = $this->config->sourceClasses()['law'] ?? null;
		$partOf = $this->config->sourcePropertyIds()['partOf'] ?? null;
		$referenceProperty = $this->config->sourcePropertyIds()['referenceCode'] ?? null;
		$contentProperty = $this->config->payloadPropertyIds()['quotation'] ?? null;
		$reference = trim( (string)( $article['reference_code'] ?? '' ) );
		if ( $class === null || $partOf === null || $referenceProperty === null || $contentProperty === null || $reference === '' ) {
			return null;
		}
		$lawTitle = trim( (string)( $law['title'] ?? '' ) );
		$label = 'Article ' . $reference . ( $lawTitle !== '' ? ' — ' . $lawTitle : '' );
		$found = $this->findItemByLabel( $label, $class );
		if ( $found === null && $lawTitle !== '' ) {
			// Legacy AddSource provision label: "{reference} of {parent}".
			$found = $this->findItemByLabel( $reference . ' of ' . $lawTitle . ' (Legislation)', $class )
				?? $this->findItemByLabel( $reference . ' of ' . $lawTitle, $class );
		}
		if ( $found !== null ) {
			return $found;
		}

		$language = (string)( $article['language'] ?? 'fr' );
		$item = new Item();
		$item->setLabel( 'fr', $label );
		$item->setDescription( 'fr', 'disposition de ' . $lawTitle );
		$item->setDescription( 'en', 'provision of ' . $lawTitle );
		$item->getStatements()->addStatement(
			$this->statement( $this->config->instanceOfPropertyId(), new EntityIdValue( new ItemId( $class ) ) )
		);
		$item->getStatements()->addStatement(
			$this->statement( $partOf, new EntityIdValue( new ItemId( $lawItemId ) ) )
		);
		$item->getStatements()->addStatement(
			$this->statement( $referenceProperty, new StringValue( $reference ) )
		);
		$item->getStatements()->addStatement(
			$this->statement( $contentProperty, new MonolingualTextValue( $language, PayloadCodec::escape( (string)$article['text'] ) ) )
		);
		$url = $this->config->sourcePropertyIds()['url'] ?? null;
		$articleId = trim( (string)( $article['article_id'] ?? '' ) );
		if ( $url !== null && $articleId !== '' ) {
			$item->getStatements()->addStatement(
				$this->statement( $url, new StringValue( 'https://www.legifrance.gouv.fr/codes/article_lc/' . rawurlencode( $articleId ) . '/' ) )
			);
		}
		return $this->save( $item, 'law corpus: create legal provision item', $user );
	}

	/**
	 * Adds the official legislation number to a reused legacy item when it
	 * has none (the manual AddSource items predate the field). The number is
	 * what Special:Law/<law_key> and the citation link resolve from.
	 */
	private function ensureLegislationNumber( string $itemId, string $lawKey, UserIdentity $user ): void {
		$property = $this->config->sourcePropertyIds()['legislationNumber'] ?? null;
		if ( $property === null || $lawKey === '' ) {
			return;
		}
		$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		if ( !$entity instanceof Item ) {
			return;
		}
		foreach ( $entity->getStatements()->getByPropertyId( PropertyIdFactory::create( $property ) ) as $statement ) {
			if ( $statement->getRank() !== \Wikibase\DataModel\Statement\Statement::RANK_DEPRECATED ) {
				return;
			}
		}
		$entity->getStatements()->addStatement(
			$this->statement( $property, new StringValue( $lawKey ) )
		);
		WikibaseRepo::getEntityStore()->saveEntity(
			$entity,
			'law corpus: add legislation number',
			$user,
			EDIT_UPDATE
		);
	}

	private function findItemByLabel( string $label, string $classId ): ?string {
		foreach ( $this->termSearch->search( $label, 'fr', 10, [ $classId ] ) as $entry ) {
			$candidate = trim( (string)( $entry['label'] ?? '' ) );
			if ( $candidate !== '' && mb_strtolower( $candidate ) === mb_strtolower( $label ) ) {
				return (string)$entry['id'];
			}
		}
		return null;
	}

	private function addJurisdiction( Item $item ): void {
		$properties = $this->config->sourcePropertyIds();
		$osm = $properties['territorialJurisdictionOsm'] ?? null;
		$label = $properties['territorialJurisdictionLabel'] ?? null;
		if ( $osm !== null ) {
			$item->getStatements()->addStatement(
				$this->statement( $osm, new StringValue( 'relation/2202162' ) )
			);
		}
		if ( $label !== null ) {
			$item->getStatements()->addStatement(
				$this->statement( $label, new StringValue( 'France' ) )
			);
		}
	}

	private function statement( string $propertyId, $value ): Statement {
		return new Statement(
			new PropertyValueSnak( PropertyIdFactory::create( $propertyId ), $value )
		);
	}

	private function dayValue( string $date ): TimeValue {
		[ $year, $month, $day ] = explode( '-', $date );
		return new TimeValue(
			'+' . $year . '-' . $month . '-' . $day . 'T00:00:00Z',
			0,
			0,
			0,
			TimeValue::PRECISION_DAY,
			TimeValue::CALENDAR_GREGORIAN
		);
	}

	private function save( Item $item, string $summary, UserIdentity $user ): ?string {
		$status = WikibaseRepo::getEntityStore()->saveEntity( $item, $summary, $user, EDIT_NEW );
		if ( !$status->isOK() ) {
			return null;
		}
		$id = $item->getId();
		return $id !== null ? $id->getSerialization() : null;
	}
}
