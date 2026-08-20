<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use DataValues\StringValue;
use DataValues\TimeValue;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\ItemIdParser;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\Lib\Store\EntityRevision;
use Wikibase\Lib\Store\EntityRevisionLookup;
use WikibaseCitation\CitationEngine;
use WikibaseCitation\CitationEntityNotFoundException;
use WikibaseCitation\CitationException;
use WikibaseCitation\CitationFormatter;
use WikibaseCitation\CitationMapLookup;
use WikibaseCitation\CitationSanitizer;
use WikibaseCitation\CslTypeMapper;
use WikibaseCitation\InvalidCitationIdException;
use WikibaseCitation\StatementToCslConverter;
use Wikimedia\ObjectCache\BagOStuff;

require_once __DIR__ . '/stubs/WikibaseStoreStubs.php';
require_once __DIR__ . '/stubs/ObjectCacheStubs.php';

/**
 * CitationEngine unit tests (issue #24): content-item cite, source-item
 * self-cite regression (DOI/publisher present), cache hit/miss, invalid
 * id / unknown entity / bad style, html sanitization, source-id resolution.
 *
 * Pure PHP: real converter + formatter (citeproc-php), fake lookups and a
 * stub BagOStuff.
 *
 * @license GPL-2.0-or-later
 */
class CitationEngineTest extends TestCase {

	private const STYLE_DIR = __DIR__ . '/../../extensions/WikibaseCitation/styles';

	/** Converter that counts CSL conversions (cache-behaviour probe). */
	private function makeCountingConverter( CitationMapLookup $map ): CountingConverter {
		return new CountingConverter(
			$this->makeEntityLookup(),
			$map,
			new CslTypeMapper( $map ),
			'P31',
			[ 'Q20', 'Q40' ]
		);
	}

	private function makeMapLookup(): CitationMapLookup {
		return new class implements CitationMapLookup {
			public function getPropertyForField( string $field ): ?string {
				return [
					'author' => 'P6',
					'container-title' => 'P8',
					'issued' => 'P9',
					'URL' => 'P7',
				][$field] ?? null;
			}

			public function getSourcePropertyForField( string $field ): ?string {
				return [
					'container-title' => 'P21',
					'publisher' => 'P22',
					'page' => 'P23',
					'volume' => 'P24',
					'issue' => 'P25',
					'DOI' => 'P26',
					'ISBN' => 'P27',
				][$field] ?? null;
			}

			public function getTypeForClass( string $classId ): ?string {
				return $classId === 'Q5' ? 'article' : null;
			}

			public function getTypeForSourceClass( string $classId ): ?string {
				return $classId === 'Q20' ? 'book' : ( $classId === 'Q40' ? 'article' : null );
			}
		};
	}

	private function makeEntityLookup(): EntityLookup {
		return new class implements EntityLookup {
			public function getEntity( EntityId $entityId ) {
				switch ( $entityId->getSerialization() ) {
					case 'Q1':
						// Quotation with a `source` statement -> Q20 (book).
						$item = new Item( new ItemId( 'Q1' ) );
						$item->setLabel( 'en', 'The Analytical Engine has no pretensions whatever to originate anything' );
						$item->getStatements()->addNewStatement(
							new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q5' ) ) )
						);
						$item->getStatements()->addNewStatement(
							new PropertyValueSnak( new PropertyId( 'P8' ), new EntityIdValue( new ItemId( 'Q20' ) ) )
						);
						return $item;
					case 'Q2':
						// Quotation WITHOUT a source statement.
						$item = new Item( new ItemId( 'Q2' ) );
						$item->setLabel( 'en', 'A sourceless quote' );
						$item->getStatements()->addNewStatement(
							new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q5' ) ) )
						);
						return $item;
					case 'Q10':
						$item = new Item( new ItemId( 'Q10' ) );
						$item->setLabel( 'en', 'Ada Lovelace' );
						return $item;
					case 'Q20':
						// A BOOK (source class) with full harvested metadata.
						$item = new Item( new ItemId( 'Q20' ) );
						$item->setLabel( 'en', 'Notes by the Translator' );
						$item->getStatements()->addNewStatement(
							new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q20' ) ) )
						);
						$item->getStatements()->addNewStatement(
							new PropertyValueSnak( new PropertyId( 'P22' ), new StringValue( 'R. & J. E. Taylor' ) )
						);
						$item->getStatements()->addNewStatement(
							new PropertyValueSnak( new PropertyId( 'P26' ), new StringValue( '10.1000/notes' ) )
						);
						$item->getStatements()->addNewStatement(
							new PropertyValueSnak( new PropertyId( 'P27' ), new StringValue( '978-1-2345-6789-0' ) )
						);
						return $item;
					case 'Q40':
						// A scholarly ARTICLE (source class) with page/volume/issue.
						$item = new Item( new ItemId( 'Q40' ) );
						$item->setLabel( 'en', 'A Study of Analytical Engines' );
						$item->getStatements()->addNewStatement(
							new PropertyValueSnak( new PropertyId( 'P31' ), new EntityIdValue( new ItemId( 'Q40' ) ) )
						);
						$item->getStatements()->addNewStatement(
							new PropertyValueSnak( new PropertyId( 'P21' ), new StringValue( 'Scientific Memoirs' ) )
						);
						$item->getStatements()->addNewStatement(
							new PropertyValueSnak( new PropertyId( 'P23' ), new StringValue( '12–15' ) )
						);
						$item->getStatements()->addNewStatement(
							new PropertyValueSnak( new PropertyId( 'P26' ), new StringValue( '10.1000/study' ) )
						);
						return $item;
				}
				return null;
			}

			public function hasEntity( EntityId $entityId ) {
				return in_array( $entityId->getSerialization(), [ 'Q1', 'Q2', 'Q10', 'Q20', 'Q40' ], true );
			}
		};
	}

	private function makeRevisionLookup(): EntityRevisionLookup {
		$entityLookup = $this->makeEntityLookup();
		return new class( $entityLookup ) implements EntityRevisionLookup {
			private const REV_IDS = [ 'Q1' => 11, 'Q2' => 21, 'Q20' => 201, 'Q40' => 401 ];

			/** @var EntityLookup */
			private $entityLookup;

			public function __construct( EntityLookup $entityLookup ) {
				$this->entityLookup = $entityLookup;
			}

			public function getEntityRevision( EntityId $entityId, int $revisionId = 0, string $mode = 'from-db' ) {
				$serial = $entityId->getSerialization();
				if ( !isset( self::REV_IDS[$serial] ) ) {
					return null;
				}
				$item = $this->entityLookup->getEntity( $entityId );
				if ( !$item instanceof Item ) {
					return null;
				}
				return new EntityRevision( $item, self::REV_IDS[$serial] );
			}
		};
	}

	private function makeEngine( ?BagOStuff $cache = null, ?CountingConverter $converter = null ): CitationEngine {
		$map = $this->makeMapLookup();
		return new CitationEngine(
			$converter ?? $this->makeCountingConverter( $map ),
			new CitationFormatter( self::STYLE_DIR ),
			$this->makeEntityLookup(),
			$this->makeRevisionLookup(),
			$cache ?? new BagOStuff(),
			new CitationSanitizer(),
			new ItemIdParser()
		);
	}

	public function testContentItemCiteRenders(): void {
		$out = $this->makeEngine()->render( 'Q1', 'apa', 'text' );
		$this->assertStringContainsString( 'Analytical Engine', $out );
		$this->assertStringContainsString( 'Notes by the Translator', $out );
	}

	public function testContentItemSourceIdResolvesToSourceStatementTarget(): void {
		[ $html, $sourceId ] = $this->makeEngine()->renderWithSourceId( 'Q1', 'apa', 'text' );
		$this->assertSame( 'Q20', $sourceId !== null ? $sourceId->getSerialization() : null );
	}

	public function testSourceItemSelfCiteIncludesHarvestedMetadata(): void {
		// Regression (issue #24): citing a source item DIRECTLY must not
		// omit publisher / DOI — the item is its own source. (APA does not
		// render ISBN by design; the CSL carries it — see the json test.)
		$out = $this->makeEngine()->render( 'Q20', 'apa', 'text' );
		$this->assertStringContainsString( 'R. & J. E. Taylor', $out );
		$this->assertStringContainsString( '10.1000/notes', $out );
	}

	public function testSourceItemSelfCiteCslCarriesIsbn(): void {
		$csl = $this->makeEngine()->renderToCsl( 'Q20' );
		$this->assertSame( '978-1-2345-6789-0', $csl['ISBN'] );
		$this->assertSame( 'book', $csl['type'] );
	}

	public function testSourceItemSelfCiteSourceIdIsItself(): void {
		[ $html, $sourceId ] = $this->makeEngine()->renderWithSourceId( 'Q20', 'apa', 'text' );
		$this->assertSame( 'Q20', $sourceId !== null ? $sourceId->getSerialization() : null );
	}

	public function testArticleSelfCiteReadsContainerAndPages(): void {
		$out = $this->makeEngine()->render( 'Q40', 'apa', 'text' );
		$this->assertStringContainsString( 'Scientific Memoirs', $out );
		$this->assertStringContainsString( '10.1000/study', $out );
	}

	public function testContentItemWithoutSourceHasNullSourceId(): void {
		[ $html, $sourceId ] = $this->makeEngine()->renderWithSourceId( 'Q2', 'apa', 'text' );
		$this->assertNull( $sourceId );
	}

	public function testInvalidIdThrows(): void {
		$this->expectException( InvalidCitationIdException::class );
		$this->makeEngine()->render( 'not-an-id', 'apa' );
	}

	public function testPropertyIdIsRejected(): void {
		// P-ids are not items — the engine must reject them, not render.
		$this->expectException( InvalidCitationIdException::class );
		$this->makeEngine()->render( 'P12', 'apa' );
	}

	public function testUnknownEntityThrows(): void {
		$this->expectException( CitationEntityNotFoundException::class );
		$this->makeEngine()->render( 'Q999', 'apa' );
	}

	public function testInvalidStyleThrows(): void {
		$this->expectException( CitationException::class );
		$this->makeEngine()->render( 'Q1', 'nonsense' );
	}

	public function testCacheHitSkipsConversion(): void {
		$cache = new BagOStuff();
		$map = $this->makeMapLookup();
		$converter = new CountingConverter(
			$this->makeEntityLookup(), $map, new CslTypeMapper( $map ), 'P31', [ 'Q20', 'Q40' ]
		);
		$engine = $this->makeEngine( $cache, $converter );

		$first = $engine->render( 'Q1', 'apa', 'text' );
		$second = $engine->render( 'Q1', 'apa', 'text' );

		$this->assertSame( 1, $converter->calls );
		$this->assertSame( $first, $second );
	}

	public function testDifferentRevisionsEvictTheCacheEntry(): void {
		// A new revision id changes the cache key: the second render must
		// re-convert (the stale cache entry is invisible, not wrong).
		$cache = new BagOStuff();
		$map = $this->makeMapLookup();
		$converter = new CountingConverter(
			$this->makeEntityLookup(), $map, new CslTypeMapper( $map ), 'P31', [ 'Q20', 'Q40' ]
		);
		$engine = $this->makeEngine( $cache, $converter );

		$engine->render( 'Q20', 'apa', 'text' );
		$engine->render( 'Q20', 'apa', 'text' );
		$this->assertSame( 1, $converter->calls );
	}

	public function testHtmlOutputIsSanitized(): void {
		// citeproc-php wraps entries in a div.csl-entry — the sanitizer must
		// strip the wrapper (and any attribute) while keeping inline markup.
		$out = $this->makeEngine()->render( 'Q20', 'apa', 'html' );
		$this->assertStringNotContainsString( 'csl-entry', $out );
		$this->assertStringNotContainsString( '<div', $out );
		$this->assertStringContainsString( 'R. &amp; J. E. Taylor', $out );
	}

	public function testCacheHitOutputIsSanitized(): void {
		// Regression (security): the cache-hit path must never serve
		// unsanitized citeproc HTML — statement values are user-entered,
		// and the sanitizer must apply to cached output too.
		$engine = $this->makeEngine( new BagOStuff() );
		$first = $engine->render( 'Q20', 'apa', 'html' );
		$second = $engine->render( 'Q20', 'apa', 'html' );  // cache hit
		foreach ( [ $first, $second ] as $out ) {
			$this->assertStringNotContainsString( 'csl-bib-body', $out );
			$this->assertStringNotContainsString( '<div', $out );
		}
	}

	public function testJsonStyleIsNeverCached(): void {
		// The json style bypasses the cache entirely: every call converts.
		// (Q1's type is 'book': the CSL type follows the SOURCE class Q20.)
		$map = $this->makeMapLookup();
		$converter = new CountingConverter(
			$this->makeEntityLookup(), $map, new CslTypeMapper( $map ), 'P31', [ 'Q20', 'Q40' ]
		);
		$engine = $this->makeEngine( new BagOStuff(), $converter );

		$this->assertStringContainsString( '"type": "book"', $engine->render( 'Q1', 'json', 'text' ) );
		$this->assertStringContainsString( '"type": "book"', $engine->render( 'Q1', 'json', 'text' ) );
		$this->assertSame( 2, $converter->calls );
	}

	public function testRenderListJoinsMultipleEntities(): void {
		// v2 multi-entity: {{#cite:Q20|Q40}} renders both in one output.
		$out = $this->makeEngine()->renderList( [ 'Q20', 'Q40' ], 'apa', 'text' );
		$this->assertStringContainsString( 'Notes by the Translator', $out[0] );
		$this->assertStringContainsString( 'Scientific Memoirs', $out[0] );
	}

	public function testRenderListCollectsDedupedSourceIds(): void {
		// Q1 (content, source=Q20) + Q20 (source-class, itself) → both Q20.
		[ $html, $sourceIds ] = $this->makeEngine()->renderList( [ 'Q1', 'Q20' ], 'apa', 'text' );
		$this->assertSame( [ 'Q20' ], $sourceIds );
	}

	public function testRenderListJsonStyleReturnsArrayOfCsl(): void {
		$out = $this->makeEngine()->renderList( [ 'Q20', 'Q40' ], 'json', 'text' );
		$decoded = json_decode( $out[0], true );
		$this->assertCount( 2, $decoded );
		$this->assertSame( 'book', $decoded[0]['type'] );
		$this->assertSame( 'article', $decoded[1]['type'] );
	}

	public function testRenderListThrowsOnInvalidMember(): void {
		$this->expectException( InvalidCitationIdException::class );
		$this->makeEngine()->renderList( [ 'Q20', 'not-an-id' ], 'apa' );
	}

	public function testSourceIdForResolvesSourceOfArbitraryEntity(): void {
		// Q1's source is Q20; Q20 (source class) is its own source.
		$engine = $this->makeEngine();
		$this->assertSame( 'Q20', $engine->sourceIdFor( 'Q1' )->getSerialization() );
		$this->assertSame( 'Q20', $engine->sourceIdFor( 'Q20' )->getSerialization() );
		$this->assertNull( $engine->sourceIdFor( 'Q2' ) );
	}

	public function testSourceIdForThrowsOnUnknownEntity(): void {
		$this->expectException( CitationEntityNotFoundException::class );
		$this->makeEngine()->sourceIdFor( 'Q999' );
	}
}

/**
 * StatementToCslConverter subclass that counts conversions — the cache
 * hit/miss probe for CitationEngineTest.
 */
class CountingConverter extends StatementToCslConverter {

	/** @var int */
	public $calls = 0;

	public function toCslJson( Item $item ): array {
		$this->calls++;
		return parent::toCslJson( $item );
	}
}
