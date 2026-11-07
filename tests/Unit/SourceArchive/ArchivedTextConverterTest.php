<?php

declare( strict_types = 1 );

namespace Tests\Unit\SourceArchive;

use EmbeddableContent\SourceArchive\ArchivedTextConverter;
use EmbeddableContent\SourceArchive\ArchivedTextException;
use EmbeddableContent\SourceArchive\HtmlPreparer;
use PHPUnit\Framework\TestCase;

/**
 * The HTML → wikitext pipeline with an injected Pandoc replacement (the real
 * binary is never invoked in unit tests).
 *
 * @license GPL-2.0-or-later
 */
class ArchivedTextConverterTest extends TestCase {

	private const URL = 'https://example.com/page';
	private const NOTE = 'Media content: view on original URL';

	public function testImagePlaceholderIsRestoredWithCaption(): void {
		$pandoc = static function ( string $html ): string {
			preg_match( '/' . sprintf( HtmlPreparer::IMAGE_TOKEN, '\d+' ) . '/', $html, $match );
			return "Hi\n\n" . ( $match[0] ?? '' );
		};
		$converter = new ArchivedTextConverter( $pandoc );

		$this->assertSame(
			"Hi\n\n([https://example.com/page " . self::NOTE . "]) Cap\n",
			$converter->convert( '<p>hi</p><img src="x.png" alt="Cap">', self::URL, self::NOTE )
		);
	}

	public function testRefIsPreservedAcrossTheConverter(): void {
		$pandoc = static function ( string $html ): string {
			preg_match( '/' . sprintf( HtmlPreparer::REF_TOKEN, '\d+' ) . '/', $html, $match );
			return 'claim ' . ( $match[0] ?? '' );
		};
		$converter = new ArchivedTextConverter( $pandoc );

		$this->assertSame(
			"claim <ref name=\"a\">source</ref>\n",
			$converter->convert( '<p>claim</p><ref name="a">source</ref>', self::URL, self::NOTE )
		);
	}

	public function testEmptyConversionReturnsNull(): void {
		$converter = new ArchivedTextConverter( static fn ( string $html ): string => '{{only}}' );

		$this->assertNull(
			$converter->convert( '<p>x</p>', self::URL, self::NOTE )
		);
	}

	public function testConverterFailurePropagates(): void {
		$converter = new ArchivedTextConverter(
			static function ( string $html ): string {
				throw new ArchivedTextException( 'boom' );
			}
		);

		$this->expectException( ArchivedTextException::class );
		$converter->convert( '<p>x</p>', self::URL, self::NOTE );
	}
}
