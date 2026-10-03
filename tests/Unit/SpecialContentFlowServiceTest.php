<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use DataValues\MonolingualTextValue;
use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\SpecialContentFlowService;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\ItemId;

/**
 * Unit tests for the entity-mode special-content pipeline.
 *
 * @license GPL-2.0-or-later
 */
class SpecialContentFlowServiceTest extends TestCase {

	private const CONFIG = [
		'instanceOf' => 'P1',
		'classes' => [ 'quotation' => 'Q2', 'code' => 'Q3', 'math' => 'Q4' ],
		'payloadProperties' => [ 'quotation' => 'P2', 'code' => 'P3', 'math' => 'P4' ],
		'programmingLanguage' => 'P5',
		'fallbackLanguages' => [ 'en' ],
		'provenance' => [ 'attributedTo' => 'P6', 'sourceUrl' => 'P7', 'date' => 'P8', 'source' => 'P28' ],
		'describes' => 'P29',
		'implementationOf' => 'P30',
		'note' => 'P31',
		'translation' => 'P32',
	];

	private function makeService(): SpecialContentFlowService {
		return new SpecialContentFlowService(
			new EmbeddableContentConfig( self::CONFIG ),
			static fn ( string $key, array $params ) => $key
		);
	}

	public function testQuotationRequiresLabelContentAndAttribution(): void {
		$service = $this->makeService();
		$noLabel = [ 'content' => 'hi' ];
		$this->assertSame( SpecialContentFlowService::ERROR_TITLE_REQUIRED, $service->prepare( 'quotation', $noLabel, true ) );
		$noPayload = [ 'label' => 'x' ];
		$this->assertSame( SpecialContentFlowService::ERROR_PAYLOAD_REQUIRED, $service->prepare( 'quotation', $noPayload, true ) );
		$noAttribution = [ 'label' => 'x', 'content' => 'hi' ];
		$this->assertSame( SpecialContentFlowService::ERROR_ATTRIBUTION_REQUIRED, $service->prepare( 'quotation', $noAttribution, true ) );
	}

	public function testMathStripsDelimitersAndEscapesPayload(): void {
		$service = $this->makeService();
		// A real trailing newline (pasted from an editor) is trimmed before
		// the delimiter strip, exactly like the form.
		$record = [ 'label' => 'E = mc²', 'content' => "\$\$E=mc^2\$\$\n", 'describes' => 'Q5' ];

		$this->assertNull( $service->prepare( 'math', $record, true ) );
		$this->assertSame( 'E=mc^2', $record['content'] );
	}

	public function testEscapesNewlinesAndBackslashes(): void {
		$service = $this->makeService();
		$record = [ 'label' => 'snippet', 'content' => "a\nb\\c\td" ];

		$this->assertNull( $service->prepare( 'code-snippet', $record, true ) );
		$this->assertSame( 'a\\nb\\\\c\\td', $record['content'] );
	}

	public function testRejectsBadLanguageAndBadField(): void {
		$service = $this->makeService();
		$record = [ 'label' => 'x', 'content' => 'hi', 'attributedTo' => 'Q6', 'language' => 'not a code!' ];
		$this->assertIsString( $service->prepare( 'quotation', $record, true ) );

		$record2 = [ 'label' => 'x', 'content' => 'hi', 'attributedTo' => 'Q6', 'describes' => 'Q5' ];
		$error = $service->prepare( 'quotation', $record2, true );
		$this->assertIsString( $error );
		$this->assertStringContainsString( 'does not accept the field(s) describes', $error );
	}

	public function testStatementSpecsForQuotation(): void {
		$service = $this->makeService();
		$record = [ 'label' => 'First words', 'content' => 'Hello world', 'language' => 'fr', 'attributedTo' => 'Q6' ];

		$specs = $service->statementSpecs( 'quotation', $record );

		$this->assertSame( 'Q2', $specs['P1']->getEntityId()->getSerialization() );
		$payload = $specs['P2'];
		$this->assertInstanceOf( MonolingualTextValue::class, $payload );
		$this->assertSame( 'fr', $payload->getLanguageCode() );
		$this->assertSame( 'Q6', $specs['P6']->getEntityId()->getSerialization() );
	}

	public function testStatementSpecsForCodeSnippetWithSubjects(): void {
		$service = $this->makeService();
		$record = [
			'label' => 'loop', 'content' => 'for i in x', 'programmingLanguage' => 'q57',
			'implementationOf' => 'Q5, Q8', 'sourceUrl' => 'https://example.org/x', 'date' => '1843-01-01',
		];

		$specs = $service->statementSpecs( 'code-snippet', $record );

		$this->assertSame( 'Q57', $specs['P5']->getEntityId()->getSerialization() );
		$this->assertSame( [ 'Q5', 'Q8' ], array_map(
			static fn ( $v ) => $v->getEntityId()->getSerialization(),
			$specs['P30']
		) );
		$this->assertSame( 'https://example.org/x', $specs['P7']->getValue() );
		$this->assertInstanceOf( TimeValue::class, $specs['P8'] );
	}

	public function testMathNoteIsEscapedAndWritten(): void {
		$service = $this->makeService();
		$record = [ 'label' => 'E', 'content' => 'E=mc^2', 'note' => "see [[File:x.png]]\nand \$x\$" ];

		$this->assertNull( $service->prepare( 'math', $record, true ) );
		// Stored escaped-at-rest (real newline → \n sequence).
		$this->assertSame( 'see [[File:x.png]]\\nand $x$', $record['note'] );

		$specs = $service->statementSpecs( 'math', $record );
		$this->assertInstanceOf( StringValue::class, $specs['P31'] );
		$this->assertSame( 'see [[File:x.png]]\\nand $x$', $specs['P31']->getValue() );
	}

	public function testNoteIsRejectedForQuotationAndCode(): void {
		$service = $this->makeService();
		$quotation = [ 'label' => 'x', 'content' => 'hi', 'attributedTo' => 'Q6', 'note' => 'n' ];
		$error = $service->prepare( 'quotation', $quotation, true );
		$this->assertIsString( $error );
		$this->assertStringContainsString( 'does not accept the field(s) note', $error );

		$code = [ 'label' => 'x', 'content' => 'print(1)', 'note' => 'n' ];
		$this->assertIsString( $service->prepare( 'code-snippet', $code, true ) );
	}

	public function testBlankNoteKeepsTheExistingStatementOnUpdate(): void {
		$service = $this->makeService();
		$record = [ 'label' => 'E', 'content' => 'E=mc^2', 'note' => 'first note' ];
		$this->assertNull( $service->prepare( 'math', $record, true ) );
		$item = $service->buildItem( 'math', $record );
		$item->setId( new ItemId( 'Q42' ) );

		// A blank note is NOT provided: the update must not manage (remove)
		// the note property — the stored note survives (no-clobber).
		$service->applyUpdate( 'math', $item, [ 'content' => 'E=mc^3' ] );

		$notes = [];
		foreach ( $item->getStatements() as $statement ) {
			if ( $statement->getPropertyId()->getSerialization() === 'P31' ) {
				$notes[] = $statement->getMainSnak()->getDataValue()->getValue();
			}
		}
		$this->assertSame( [ 'first note' ], $notes );
	}

	public function testApplyUpdateIsNoClobber(): void {
		$service = $this->makeService();
		$record = [ 'label' => 'q', 'content' => 'hello', 'attributedTo' => 'Q6' ];
		$item = $service->buildItem( 'quotation', $record );
		// applyUpdate runs on a LOADED item (the caller fetched it from the
		// store) — it needs the id to assign statement GUIDs.
		$item->setId( new ItemId( 'Q42' ) );

		$service->applyUpdate( 'quotation', $item, [ 'content' => 'goodbye' ] );

		$properties = [];
		foreach ( $item->getStatements() as $statement ) {
			$properties[] = $statement->getPropertyId()->getSerialization();
		}
		// Payload replaced once; attribution kept.
		$this->assertSame( 1, count( array_keys( $properties, 'P2', true ) ) );
		$this->assertContains( 'P6', $properties );
		$this->assertContains( 'P1', $properties );
		$payload = null;
		foreach ( $item->getStatements() as $statement ) {
			if ( $statement->getPropertyId()->getSerialization() === 'P2' ) {
				$payload = $statement->getMainSnak()->getDataValue();
				// The replaced statement carries a GUID (the entity-page
				// client matches statements to the DOM by GUID — a
				// GUID-less statement renders as an empty edit-mode row).
				$this->assertNotNull( $statement->getGuid() );
			}
		}
		$this->assertSame( 'goodbye', $payload->getText() );
	}

	// ------------------------------------------------------ translations

	public function testQuotationTranslationsAreEscapedAndWritten(): void {
		$service = $this->makeService();
		$record = [
			'label' => 'q', 'content' => 'Hello', 'language' => 'en', 'attributedTo' => 'Q6',
			'translations' => [ [ 'language' => 'fr', 'content' => "Bonjour\nmonde" ] ],
		];

		$this->assertNull( $service->prepare( 'quotation', $record, true ) );
		// Stored escaped-at-rest (real newline → \n sequence).
		$this->assertSame( 'Bonjour\\nmonde', $record['translations'][0]['content'] );

		$specs = $service->statementSpecs( 'quotation', $record );
		$this->assertInstanceOf( MonolingualTextValue::class, $specs['P2'] );
		$this->assertSame( 'en', $specs['P2']->getLanguageCode() );
		$this->assertIsArray( $specs['P32'] );
		$this->assertInstanceOf( MonolingualTextValue::class, $specs['P32'][0] );
		$this->assertSame( 'fr', $specs['P32'][0]->getLanguageCode() );
		$this->assertSame( 'Bonjour\\nmonde', $specs['P32'][0]->getText() );
	}

	public function testTranslationValidationRejectsBadRows(): void {
		$service = $this->makeService();
		$base = [ 'label' => 'q', 'content' => 'Hello', 'language' => 'en', 'attributedTo' => 'Q6' ];

		$badLanguage = $base + [ 'translations' => [ [ 'language' => 'xx!', 'content' => 'x' ] ] ];
		$this->assertStringContainsString(
			'not a valid language code',
			(string)$service->prepare( 'quotation', $badLanguage, true )
		);

		$sameAsOriginal = $base + [ 'translations' => [ [ 'language' => 'en', 'content' => 'x' ] ] ];
		$this->assertStringContainsString(
			'same as the original',
			(string)$service->prepare( 'quotation', $sameAsOriginal, true )
		);

		$duplicate = $base + [ 'translations' => [
			[ 'language' => 'fr', 'content' => 'a' ],
			[ 'language' => 'FR', 'content' => 'b' ],
		] ];
		$this->assertStringContainsString(
			'duplicate',
			(string)$service->prepare( 'quotation', $duplicate, true )
		);

		$blankText = $base + [ 'translations' => [ [ 'language' => 'fr', 'content' => '   ' ] ] ];
		$this->assertStringContainsString(
			'has no text',
			(string)$service->prepare( 'quotation', $blankText, true )
		);
	}

	public function testBlankTranslationRowsAreDropped(): void {
		$service = $this->makeService();
		$record = [
			'label' => 'q', 'content' => 'Hello', 'language' => 'en', 'attributedTo' => 'Q6',
			'translations' => [
				[ 'language' => '', 'content' => '' ],
				[ 'language' => 'fr', 'content' => 'Bonjour' ],
			],
		];
		$this->assertNull( $service->prepare( 'quotation', $record, true ) );
		$this->assertCount( 1, $record['translations'] );
		$this->assertSame( 'fr', $record['translations'][0]['language'] );
	}

	public function testApplyUpdateClearsTranslationsWhenTheListIsPresentAndEmpty(): void {
		$service = $this->makeService();
		$record = [
			'label' => 'q', 'content' => 'Hello', 'language' => 'en', 'attributedTo' => 'Q6',
			'translations' => [ [ 'language' => 'fr', 'content' => 'Bonjour' ] ],
		];
		$service->prepare( 'quotation', $record, true );
		$item = $service->buildItem( 'quotation', $record );
		$item->setId( new ItemId( 'Q42' ) );

		// A present-but-empty list is authoritative: it clears the translations.
		$service->applyUpdate( 'quotation', $item, [
			'content' => 'Hi', 'language' => 'en', 'translations' => [],
		] );

		$this->assertSame( 0, $this->countProperty( $item, 'P32' ) );
	}

	public function testApplyUpdatePreservesTranslationsWhenAbsent(): void {
		$service = $this->makeService();
		$record = [
			'label' => 'q', 'content' => 'Hello', 'language' => 'en', 'attributedTo' => 'Q6',
			'translations' => [ [ 'language' => 'fr', 'content' => 'Bonjour' ] ],
		];
		$service->prepare( 'quotation', $record, true );
		$item = $service->buildItem( 'quotation', $record );
		$item->setId( new ItemId( 'Q42' ) );

		// No translations key → the property is not managed (no-clobber).
		$service->applyUpdate( 'quotation', $item, [ 'content' => 'Hi' ] );

		$this->assertSame( 1, $this->countProperty( $item, 'P32' ) );
	}

	private function countProperty( \Wikibase\DataModel\Entity\Item $item, string $propertyId ): int {
		$count = 0;
		foreach ( $item->getStatements() as $statement ) {
			if ( $statement->getPropertyId()->getSerialization() === $propertyId ) {
				$count++;
			}
		}
		return $count;
	}
}
