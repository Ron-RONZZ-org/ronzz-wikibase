<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\ProviderException;
use EmbeddableContent\Fetch\WorkRecord;
use EmbeddableContent\Fetch\YouTubeProvider;
use PHPUnit\Framework\TestCase;
use Wikimedia\ObjectCache\BagOStuff;

require_once __DIR__ . '/../stubs/ObjectCacheStubs.php';

/**
 * @license GPL-2.0-or-later
 */
final class YouTubeProviderTest extends TestCase {

	public function testSearchChannelsMapsResults(): void {
		$http = ( new FakeHttpClient() )->onJson( 'search', [
			'items' => [
				[
					'id' => [ 'kind' => 'youtube#channel', 'channelId' => 'UC_abc123' ],
					'snippet' => [
						'title' => 'The Flameshot Channel',
						'description' => 'Screenshots done right',
						'publishedAt' => '2021-03-05T12:00:00Z',
					],
				],
			],
		] );
		$provider = new YouTubeProvider( $http, 'test-key' );

		$records = $provider->searchChannels( 'flameshot' );

		$this->assertCount( 1, $records );
		$this->assertInstanceOf( WorkRecord::class, $records[0] );
		$this->assertSame( 'The Flameshot Channel', $records[0]->title );
		$this->assertSame( 'UC_abc123', $records[0]->youtubeChannelId );
		$this->assertSame( 2021, $records[0]->issuedYear );
		$this->assertSame( 'youtube', $records[0]->provider );
		$this->assertSame( 'https://www.youtube.com/channel/UC_abc123', $records[0]->url );
	}

	public function testSearchVideosSendsCappedParamsAndKey(): void {
		$http = ( new FakeHttpClient() )->onJson( 'search', [ 'items' => [] ] );
		$provider = new YouTubeProvider( $http, 'test-key', 10.0, 10 );

		$provider->searchVideos( 'kde plasma' );

		$call = $http->calls[0];
		$this->assertSame( 'video', $call['params']['type'] ?? null );
		$this->assertSame( 'kde plasma', $call['params']['q'] ?? null );
		$this->assertSame( 10, $call['params']['maxResults'] ?? null );
		$this->assertSame( 'test-key', $call['params']['key'] ?? null );
	}

	public function testByVideoUrlResolvesExactly(): void {
		$http = ( new FakeHttpClient() )->onJson( 'videos', [
			'items' => [
				[
					'id' => 'dQw4w9WgXcQ',
					'snippet' => [
						'title' => 'Rick Astley',
						'description' => 'Never gonna give you up',
						'channelId' => 'UCuAXFkgsw1L7xaCfnd5JJOw',
						'channelTitle' => 'Rick Astley',
						'publishedAt' => '2009-10-25T06:57:33Z',
					],
				],
			],
		] );
		$provider = new YouTubeProvider( $http, 'test-key' );

		$record = $provider->byUrl( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );

		$this->assertNotNull( $record );
		$this->assertSame( 'dQw4w9WgXcQ', $record->youtubeVideoId );
		$this->assertSame( 'https://youtu.be/dQw4w9WgXcQ', $record->url );
		$this->assertSame( 'Rick Astley', $record->channelTitle );
		$this->assertSame( 2009, $record->issuedYear );
		$this->assertSame( 'youtube', $record->provider );
	}

	public function testByChannelUrlWithHandle(): void {
		$http = ( new FakeHttpClient() )->onJson( 'channels', [
			'items' => [
				[
					'id' => 'UCuAXFkgsw1L7xaCfnd5JJOw',
					'snippet' => [
						'title' => 'Rick Astley',
						'description' => 'Official channel',
						'publishedAt' => '2009-10-25T06:57:33Z',
					],
				],
			],
		] );
		$provider = new YouTubeProvider( $http, 'test-key' );

		$record = $provider->byUrl( 'https://www.youtube.com/@rickastley' );

		$this->assertNotNull( $record );
		$this->assertSame( 'UCuAXFkgsw1L7xaCfnd5JJOw', $record->youtubeChannelId );
		// forHandle, not id — the handle must be resolved server-side.
		$this->assertSame( 'rickastley', $http->calls[0]['params']['forHandle'] ?? null );
	}

	public function testByChannelUrlWithChannelId(): void {
		$http = ( new FakeHttpClient() )->onJson( 'channels', [ 'items' => [] ] );
		$provider = new YouTubeProvider( $http, 'test-key' );

		$record = $provider->byUrl( 'https://www.youtube.com/channel/UCuAXFkgsw1L7xaCfnd5JJOw' );

		$this->assertNull( $record );
		$this->assertSame( 'UCuAXFkgsw1L7xaCfnd5JJOw', $http->calls[0]['params']['id'] ?? null );
	}

	public function testByUrlWithUnknownShapeReturnsNullWithoutApiCall(): void {
		$http = new FakeHttpClient();
		$provider = new YouTubeProvider( $http, 'test-key' );

		$this->assertNull( $provider->byUrl( 'https://example.org/not-youtube' ) );
		$this->assertSame( [], $http->calls );
	}

	public function testMissingKeyThrows(): void {
		$provider = new YouTubeProvider( new FakeHttpClient(), '' );

		$this->expectException( ProviderException::class );
		$this->expectExceptionMessage( 'missing API key' );
		$provider->searchChannels( 'flameshot' );
	}

	public function testMemoizesNameSearchWhenTtlConfigured(): void {
		$http = ( new FakeHttpClient() )->onJson( 'search', [
			'items' => [
				[
					'id' => [ 'kind' => 'youtube#channel', 'channelId' => 'UC_abc123' ],
					'snippet' => [ 'title' => 'Cached Channel' ],
				],
			],
		] );
		$provider = new YouTubeProvider( $http, 'test-key', 10.0, 10, new BagOStuff(), 86400 );

		$provider->searchChannels( 'same query' );
		$provider->searchChannels( 'same query' );

		// A cache hit must not re-query the API.
		$this->assertCount( 1, $http->calls );
	}

	public function testSkipsCacheWhenTtlZero(): void {
		$http = ( new FakeHttpClient() )->onJson( 'search', [ 'items' => [] ] );
		$provider = new YouTubeProvider( $http, 'test-key', 10.0, 10, new BagOStuff(), 0 );

		$provider->searchChannels( 'same query' );
		$provider->searchChannels( 'same query' );

		$this->assertCount( 2, $http->calls );
	}
}
