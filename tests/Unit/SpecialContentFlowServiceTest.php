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

	public function testApplyUpdateIsNoClobber(): void {
		$service = $this->makeService();
		$record = [ 'label' => 'q', 'content' => 'hello', 'attributedTo' => 'Q6' ];
		$item = $service->buildItem( 'quotation', $record );

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
			}
		}
		$this->assertSame( 'goodbye', $payload->getText() );
	}
}
