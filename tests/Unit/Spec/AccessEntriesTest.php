<?php

declare( strict_types = 1 );

namespace Tests\Unit\Spec;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\PropertyIdFactory;
use EmbeddableContent\Spec\AccessEntries;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/** Normalization of the multi-access record (pure). */
final class AccessEntriesTest extends TestCase {

	public function testNormalizeDecodesJsonAndDropsEmptyRows(): void {
		$entries = AccessEntries::normalize( json_encode( [
			[ 'type' => 'url', 'url' => ' https://example.org ', 'public' => '1', 'archive' => '1' ],
			[ 'type' => 'url', 'url' => '' ],
			[ 'type' => 'file', 'fileTitle' => 'File:A.pdf', 'public' => '', 'license' => 'Q1' ],
			[ 'type' => 'nope' ],
		] ) );
		$this->assertCount( 2, $entries );
		$this->assertSame( 'https://example.org', $entries[0]['url'] );
		$this->assertSame( '1', $entries[0]['archive'] );
		$this->assertSame( 'File:A.pdf', $entries[1]['fileTitle'] );
		$this->assertSame( '', $entries[1]['public'] );
	}

	public function testNormalizeKeepsLanguageAndFileId(): void {
		$entries = AccessEntries::normalize( [
			[ 'type' => 'file', 'fileTitle' => 'File:A.pdf', 'public' => '1', 'license' => 'Q1',
				'language' => ' EN ', 'fileId' => 'q42' ],
		] );
		$this->assertSame( 'EN', $entries[0]['language'] );
		$this->assertSame( 'Q42', $entries[0]['fileId'] );
	}

	public function testNormalizeDropsInvalidFileId(): void {
		$entries = AccessEntries::normalize( [
			[ 'type' => 'file', 'fileTitle' => 'File:A.pdf', 'fileId' => 'not-a-qid' ],
		] );
		$this->assertArrayNotHasKey( 'fileId', $entries[0] );
	}

	public function testNormalizeAcceptsArray(): void {
		$entries = AccessEntries::normalize( [
			[ 'type' => 'url', 'url' => 'https://example.org/x', 'public' => '1' ],
		] );
		$this->assertSame( 'https://example.org/x', $entries[0]['url'] );
	}

	public function testNormalizeDropsEmptyAndInvalidTypes(): void {
		$this->assertSame( [], AccessEntries::normalize( [] ) );
		$this->assertSame( [], AccessEntries::normalize( 'not json' ) );
		$this->assertSame( [], AccessEntries::normalize( null ) );
	}

	public function testAttachExtractedTextAddsAndReplacesTheQualifier(): void {
		$config = new EmbeddableContentConfig( [
			'instanceOf' => 'P1',
			'classes' => [ 'quotation' => 'Q1', 'code' => 'Q2', 'math' => 'Q3' ],
			'payloadProperties' => [ 'quotation' => 'P2', 'code' => 'P3', 'math' => 'P4' ],
			'programmingLanguage' => 'P5',
			'provenance' => [ 'attributedTo' => 'P6', 'sourceUrl' => 'P7', 'date' => 'P8', 'source' => 'P9' ],
			'fallbackLanguages' => [ 'en' ],
			'sourceProperties' => [ 'hasAccessFile' => 'P20', 'extractedText' => 'P21', 'file' => 'P22' ],
		] );
		$source = new Item( new ItemId( 'Q100' ) );
		$source->getStatements()->addNewStatement( new PropertyValueSnak(
			PropertyIdFactory::create( 'P20' ),
			new EntityIdValue( new ItemId( 'Q200' ) )
		) );

		$this->assertTrue( AccessEntries::attachExtractedText( $source, $config, 'Q200', 'Q300' ) );
		$this->assertFalse( AccessEntries::attachExtractedText( $source, $config, 'Q999', 'Q301' ) );

		$statement = null;
		foreach ( $source->getStatements()->getByPropertyId( PropertyIdFactory::create( 'P20' ) ) as $candidate ) {
			$statement = $candidate;
			break;
		}
		$this->assertNotNull( $statement );
		$values = [];
		foreach ( $statement->getQualifiers() as $qualifier ) {
			if ( $qualifier->getPropertyId()->getSerialization() === 'P21' ) {
				$values[] = $qualifier->getDataValue()->getEntityId()->getSerialization();
			}
		}
		$this->assertSame( [ 'Q300' ], $values );
	}

	public function testHttpUrlValidation(): void {
		$this->assertTrue( AccessEntries::isHttpUrl( 'https://example.org' ) );
		$this->assertTrue( AccessEntries::isHttpUrl( 'http://example.org' ) );
		$this->assertFalse( AccessEntries::isHttpUrl( 'ftp://example.org' ) );
		$this->assertFalse( AccessEntries::isHttpUrl( '' ) );
	}
}
