<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\WikimediaFileUrl;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EmbeddableContent\Fetch\WikimediaFileUrl
 * @license GPL-2.0-or-later
 */
final class WikimediaFileUrlTest extends TestCase {

	/** @return array<string,array{string,bool}> */
	public static function hostProvider(): array {
		return [
			'commons' => [ 'https://commons.wikimedia.org/wiki/File:A.jpg', true ],
			'enwiki' => [ 'https://en.wikipedia.org/wiki/File:A.jpg', true ],
			'upload' => [ 'https://upload.wikimedia.org/wikipedia/commons/A.jpg', true ],
			'wikidata' => [ 'https://www.wikidata.org/wiki/Q42', true ],
			'other' => [ 'https://example.com/image.jpg', false ],
			'subdomain-other' => [ 'https://cdn.notwikimedia.org/x.jpg', false ],
		];
	}

	/** @dataProvider hostProvider */
	public function testIsWikimediaHost( string $url, bool $expected ): void {
		$this->assertSame( $expected, WikimediaFileUrl::isWikimediaHost( $url ) );
	}

	/** @return array<string,array{string,?string}> */
	public static function titleProvider(): array {
		return [
			'wiki-file-underscores' => [
				'https://en.wikipedia.org/wiki/File:Albert_Einstein_1947.jpg',
				'File:Albert Einstein 1947.jpg',
			],
			'wiki-file-spaces' => [ 'https://commons.wikimedia.org/wiki/File:Example.svg', 'File:Example.svg' ],
			'special-filepath' => [
				'https://commons.wikimedia.org/wiki/Special:FilePath/Example.jpg',
				'File:Example.jpg',
			],
			'upload-original' => [
				'https://upload.wikimedia.org/wikipedia/commons/8/85/Example.jpg',
				'File:Example.jpg',
			],
			'upload-thumb' => [
				'https://upload.wikimedia.org/wikipedia/commons/thumb/8/85/Example.jpg/220px-Example.jpg',
				'File:Example.jpg',
			],
			'upload-query' => [
				'https://upload.wikimedia.org/wikipedia/commons/8/85/Example.jpg?download=1',
				'File:Example.jpg',
			],
			// Percent-encoded file names must be decoded before the title
			// reaches the Commons API — the literal "%28"/"%29" was sent
			// as-is and matched no file (the http-bad-status fallback bug).
			'upload-thumb-encoded-name' => [
				'https://upload.wikimedia.org/wikipedia/commons/thumb/c/c0/Magnus-manske-2024_%28cropped%29.jpg/250px-Magnus-manske-2024_%28cropped%29.jpg?utm_source=fr.wikipedia.org&utm_campaign=parser&utm_content=thumbnail',
				'File:Magnus-manske-2024 (cropped).jpg',
			],
			'wiki-file-encoded' => [
				'https://commons.wikimedia.org/wiki/File:Foo%20Bar%28baz%29.svg',
				'File:Foo Bar(baz).svg',
			],
			'special-filepath-encoded' => [
				'https://commons.wikimedia.org/wiki/Special:FilePath/Foo%20Bar.jpg',
				'File:Foo Bar.jpg',
			],
			'upload-original-encoded' => [
				'https://upload.wikimedia.org/wikipedia/commons/8/85/Foo%20Bar.jpg',
				'File:Foo Bar.jpg',
			],
			'upload-encoded-percent-literal' => [
				'https://upload.wikimedia.org/wikipedia/commons/8/85/100%25.jpg',
				'File:100%.jpg',
			],
			'not-a-file-page' => [ 'https://en.wikipedia.org/wiki/Albert_Einstein', null ],
			'not-wikimedia' => [ 'https://example.com/pic.jpg', null ],
			'no-path' => [ 'https://commons.wikimedia.org', null ],
		];
	}

	/** @dataProvider titleProvider */
	public function testFileTitle( string $url, ?string $expected ): void {
		$this->assertSame( $expected, WikimediaFileUrl::fileTitle( $url ) );
	}

	/** @return array<string,array{string,?string}> */
	public static function originalSvgProvider(): array {
		return [
			// The reported shape: an SVG thumbnail whose rendered PNG must NOT
			// be uploaded under the ".svg" destination name.
			'svg-thumb' => [
				'https://upload.wikimedia.org/wikipedia/commons/thumb/9/96/Parallelogram_law_squares.svg/960px-Parallelogram_law_squares.svg.png?utm_source=en.wikipedia.org&utm_campaign=imageinfo&utm_content=thumbnail',
				'https://upload.wikimedia.org/wikipedia/commons/9/96/Parallelogram_law_squares.svg',
			],
			// thumb.wikimedia.org serves the same renditions; the original is
			// returned on the canonical upload.wikimedia.org host (it 301s
			// originals there).
			'svg-thumb-alias-host' => [
				'https://thumb.wikimedia.org/wikipedia/commons/thumb/9/96/Parallelogram_law_squares.svg/960px-Parallelogram_law_squares.svg.png',
				'https://upload.wikimedia.org/wikipedia/commons/9/96/Parallelogram_law_squares.svg',
			],
			// A language-wiki (non-Commons) SVG thumbnail.
			'svg-thumb-language-repo' => [
				'https://upload.wikimedia.org/wikipedia/en/thumb/a/ab/Local_Logo.svg/220px-Local_Logo.svg.png',
				'https://upload.wikimedia.org/wikipedia/en/a/ab/Local_Logo.svg',
			],
			// Percent-encoded names keep their encoding (this is a FETCH URL,
			// not an API title).
			'svg-thumb-encoded-name' => [
				'https://upload.wikimedia.org/wikipedia/commons/thumb/c/c0/Foo%20Bar%28baz%29.svg/250px-Foo%20Bar%28baz%29.svg.png',
				'https://upload.wikimedia.org/wikipedia/commons/c/c0/Foo%20Bar%28baz%29.svg',
			],
			// A raster thumbnail is left alone (the PNG rendition is the
			// intended, smaller fetch).
			'jpg-thumb' => [
				'https://upload.wikimedia.org/wikipedia/commons/thumb/8/85/Example.jpg/220px-Example.jpg',
				null,
			],
			// An already-original SVG URL is not a rendition.
			'svg-original' => [
				'https://upload.wikimedia.org/wikipedia/commons/9/96/Foo.svg',
				null,
			],
			// A PDF/TIFF rendition is not an SVG.
			'pdf-thumb' => [
				'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Doc.pdf/page1-220px-Doc.pdf.jpg',
				null,
			],
			'not-wikimedia' => [ 'https://example.com/thumb/9/96/Foo.svg/220px-Foo.svg.png', null ],
			'not-a-thumb' => [ 'https://en.wikipedia.org/wiki/File:Foo.svg', null ],
		];
	}

	/** @dataProvider originalSvgProvider */
	public function testOriginalSvgUrl( string $url, ?string $expected ): void {
		$this->assertSame( $expected, WikimediaFileUrl::originalSvgUrl( $url ) );
	}

	public function testCommonsQuery(): void {
		$query = WikimediaFileUrl::commonsQuery( 'https://en.wikipedia.org/wiki/File:Einstein_1947.jpg' );
		$this->assertSame( 'https://commons.wikimedia.org/w/api.php', $query['api'] );
		$this->assertSame( 'File:Einstein 1947.jpg', $query['title'] );

		$this->assertNull( WikimediaFileUrl::commonsQuery( 'https://example.com/x.jpg' ) );
	}
}
