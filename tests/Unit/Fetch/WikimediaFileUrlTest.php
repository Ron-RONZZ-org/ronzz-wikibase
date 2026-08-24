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
			'not-a-file-page' => [ 'https://en.wikipedia.org/wiki/Albert_Einstein', null ],
			'not-wikimedia' => [ 'https://example.com/pic.jpg', null ],
			'no-path' => [ 'https://commons.wikimedia.org', null ],
		];
	}

	/** @dataProvider titleProvider */
	public function testFileTitle( string $url, ?string $expected ): void {
		$this->assertSame( $expected, WikimediaFileUrl::fileTitle( $url ) );
	}

	public function testCommonsQuery(): void {
		$query = WikimediaFileUrl::commonsQuery( 'https://en.wikipedia.org/wiki/File:Einstein_1947.jpg' );
		$this->assertSame( 'https://commons.wikimedia.org/w/api.php', $query['api'] );
		$this->assertSame( 'File:Einstein 1947.jpg', $query['title'] );

		$this->assertNull( WikimediaFileUrl::commonsQuery( 'https://example.com/x.jpg' ) );
	}
}
