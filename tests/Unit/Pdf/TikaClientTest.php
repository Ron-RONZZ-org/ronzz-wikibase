<?php

declare( strict_types = 1 );

namespace Tests\Unit\Pdf;

use EmbeddableContent\Pdf\TikaClient;
use EmbeddableContent\Pdf\TikaException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Pdf\TikaClient
 * @license GPL-2.0-or-later
 */
final class TikaClientTest extends TestCase {

	private string $pdf;

	protected function setUp(): void {
		$this->pdf = tempnam( sys_get_temp_dir(), 'tika-test' );
		file_put_contents( $this->pdf, '%PDF-1.4 test' );
	}

	protected function tearDown(): void {
		@unlink( $this->pdf );
	}

	public function testParsesTheTikaFourEnvelope(): void {
		$client = new TikaClient( 'http://tika:9998/tika/json/md', 10.0, static function (): array {
			return [ 200, json_encode( [ 'tk:content' => "# Heading\n\nText." ] ), '' ];
		} );
		$result = $client->extractMarkdown( $this->pdf );
		$this->assertSame( "# Heading\n\nText.", $result['markdown'] );
		$this->assertFalse( $result['partial'] );
	}

	public function testAcceptsTheTikaThreeContentField(): void {
		$client = new TikaClient( 'http://tika', 10.0, static function (): array {
			return [ 200, json_encode( [ 'X-TIKA:content' => 'Legacy.' ] ), '' ];
		} );
		$this->assertSame( 'Legacy.', $client->extractMarkdown( $this->pdf )['markdown'] );
	}

	public function testMarksAPartialContainerException(): void {
		$client = new TikaClient( 'http://tika', 10.0, static function (): array {
			return [ 200, json_encode( [
				'tk:content' => 'Partial text',
				'status' => 'PARTIAL_TIMEOUT',
				'tk:exception:container-exception' => 'timed out',
			] ), '' ];
		} );
		$result = $client->extractMarkdown( $this->pdf );
		$this->assertTrue( $result['partial'] );
	}

	public function testHttpFailureThrows(): void {
		$client = new TikaClient( 'http://tika', 10.0, static function (): array {
			return [ 503, json_encode( [ 'status' => 'TIMEOUT' ] ), '' ];
		} );
		$this->expectException( TikaException::class );
		$client->extractMarkdown( $this->pdf );
	}

	public function testNonJsonThrows(): void {
		$client = new TikaClient( 'http://tika', 10.0, static function (): array {
			return [ 200, '<html>', '' ];
		} );
		$this->expectException( TikaException::class );
		$client->extractMarkdown( $this->pdf );
	}

}
