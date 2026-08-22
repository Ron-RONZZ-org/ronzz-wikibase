<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

use Wikimedia\ObjectCache\BagOStuff;

/**
 * YouTube Data API v3 provider (Special:AddSource youtubeChannel /
 * youtubeVideo classes).
 *
 * Name searches run search.list capped at $searchCap results (the API bills
 * 100 units per search.list call regardless of count — the cap is a UX
 * choice, not a cost one); URL lookups resolve EXACTLY via videos.list /
 * channels.list (1 unit each) and return null when nothing matches the
 * provided URL.
 *
 * The key is server-side only (deploy-injected via the instance config map,
 * never committed). An optional BagOStuff cache (ttl > 0) memoizes name
 * searches by normalized query + kind; the instance default is 0 (off).
 *
 * @license GPL-2.0-or-later
 */
final class YouTubeProvider {

	private const API = 'https://www.googleapis.com/youtube/v3';

	private HttpClientInterface $http;
	private string $apiKey;
	private float $timeout;
	private int $searchCap;
	private int $cacheTtl;
	private ?BagOStuff $cache;

	public function __construct(
		HttpClientInterface $http,
		string $apiKey,
		float $timeout = 10.0,
		int $searchCap = 10,
		?BagOStuff $cache = null,
		int $cacheTtl = 0
	) {
		$this->http = $http;
		$this->apiKey = $apiKey;
		$this->timeout = $timeout;
		$this->searchCap = $searchCap;
		$this->cache = $cache;
		$this->cacheTtl = $cacheTtl;
	}

	/** @return WorkRecord[] */
	public function searchChannels( string $name ): array {
		return $this->memoize( 'channel:' . $this->normalize( $name ), function () use ( $name ): array {
			$data = $this->get( 'search', [
				'part' => 'snippet',
				'type' => 'channel',
				'q' => $name,
				'maxResults' => $this->searchCap,
			] );
			$out = [];
			foreach ( $data['items'] ?? [] as $item ) {
				$snippet = $item['snippet'] ?? [];
				$channelId = (string)( $item['id']['channelId'] ?? '' );
				if ( $channelId === '' || empty( $snippet['title'] ) ) {
					continue;
				}
				$out[] = $this->channelRecord( $channelId, $snippet );
			}
			return $out;
		} );
	}

	/** @return WorkRecord[] */
	public function searchVideos( string $name ): array {
		return $this->memoize( 'video:' . $this->normalize( $name ), function () use ( $name ): array {
			$data = $this->get( 'search', [
				'part' => 'snippet',
				'type' => 'video',
				'q' => $name,
				'maxResults' => $this->searchCap,
			] );
			$out = [];
			foreach ( $data['items'] ?? [] as $item ) {
				$snippet = $item['snippet'] ?? [];
				$videoId = (string)( $item['id']['videoId'] ?? '' );
				if ( $videoId === '' || empty( $snippet['title'] ) ) {
					continue;
				}
				$out[] = $this->videoRecord( $videoId, $snippet );
			}
			return $out;
		} );
	}

	/**
	 * Exact resolution of a YouTube URL (channel or video). Returns null
	 * when the URL carries no recognizable YouTube identifier or the API
	 * has no record for it — the caller surfaces the "no match" state.
	 */
	public function byUrl( string $url ): ?WorkRecord {
		$videoId = self::extractVideoId( $url );
		if ( $videoId !== null ) {
			return $this->byVideoId( $videoId );
		}
		$channelId = self::extractChannelId( $url );
		if ( $channelId !== null ) {
			return $this->byChannelId( $channelId );
		}
		$handle = self::extractHandle( $url );
		if ( $handle !== null ) {
			return $this->byHandle( $handle );
		}
		return null;
	}

	/** True when the URL looks like a YouTube video URL. */
	public static function isVideoUrl( string $url ): bool {
		return self::extractVideoId( $url ) !== null;
	}

	/** True when the URL looks like a YouTube channel URL. */
	public static function isChannelUrl( string $url ): bool {
		return self::extractChannelId( $url ) !== null || self::extractHandle( $url ) !== null;
	}

	private function byVideoId( string $id ): ?WorkRecord {
		$data = $this->get( 'videos', [ 'part' => 'snippet', 'id' => $id ] );
		$item = $data['items'][0] ?? null;
		if ( $item === null ) {
			return null;
		}
		return $this->videoRecord( $id, $item['snippet'] ?? [] );
	}

	private function byChannelId( string $id ): ?WorkRecord {
		$data = $this->get( 'channels', [ 'part' => 'snippet', 'id' => $id ] );
		$item = $data['items'][0] ?? null;
		if ( $item === null ) {
			return null;
		}
		return $this->channelRecord( $id, $item['snippet'] ?? [] );
	}

	private function byHandle( string $handle ): ?WorkRecord {
		$data = $this->get( 'channels', [ 'part' => 'snippet', 'forHandle' => $handle ] );
		$item = $data['items'][0] ?? null;
		if ( $item === null ) {
			return null;
		}
		return $this->channelRecord( (string)( $item['id'] ?? '' ), $item['snippet'] ?? [] );
	}

	/** @param array<string,mixed> $snippet */
	private function channelRecord( string $channelId, array $snippet ): WorkRecord {
		return new WorkRecord(
			title: (string)( $snippet['title'] ?? '' ),
			description: isset( $snippet['description'] ) ? (string)$snippet['description'] : null,
			issuedYear: $this->publishedYear( $snippet['publishedAt'] ?? '' ),
			provider: 'youtube',
			providerId: $channelId !== '' ? $channelId : null,
			youtubeChannelId: $channelId !== '' ? $channelId : null,
			url: $channelId !== '' ? 'https://www.youtube.com/channel/' . $channelId : null,
			channelTitle: (string)( $snippet['title'] ?? '' )
		);
	}

	/** @param array<string,mixed> $snippet */
	private function videoRecord( string $videoId, array $snippet ): WorkRecord {
		return new WorkRecord(
			title: (string)( $snippet['title'] ?? '' ),
			description: isset( $snippet['description'] ) ? (string)$snippet['description'] : null,
			issuedYear: $this->publishedYear( $snippet['publishedAt'] ?? '' ),
			provider: 'youtube',
			providerId: $videoId !== '' ? $videoId : null,
			youtubeVideoId: $videoId !== '' ? $videoId : null,
			url: $videoId !== '' ? 'https://youtu.be/' . $videoId : null,
			channelTitle: isset( $snippet['channelTitle'] ) ? (string)$snippet['channelTitle'] : null
		);
	}

	/**
	 * GETs a YouTube API endpoint with the key attached; throws
	 * ProviderException on transport/HTTP failures (never swallowed).
	 *
	 * @return array<string,mixed>
	 */
	private function get( string $endpoint, array $params ): array {
		if ( $this->apiKey === '' ) {
			throw new ProviderException( 'YouTube provider not configured (missing API key)' );
		}
		$params['key'] = $this->apiKey;
		return $this->http->getJson( self::API . '/' . $endpoint, $params, $this->timeout );
	}

	/**
	 * Memoizes a name-search closure keyed by normalized query + kind when a
	 * cache with ttl > 0 is configured; otherwise runs the closure.
	 *
	 * @param callable():array $fresh
	 * @return array<int,WorkRecord>
	 */
	private function memoize( string $key, callable $fresh ): array {
		if ( $this->cache === null || $this->cacheTtl <= 0 ) {
			return $fresh();
		}
		$cached = $this->cache->get( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$result = $fresh();
		$this->cache->set( $key, $result, $this->cacheTtl );
		return $result;
	}

	private function normalize( string $name ): string {
		return strtolower( preg_replace( '/\s+/', ' ', trim( $name ) ) ?? '' );
	}

	private function publishedYear( string $publishedAt ): ?int {
		return preg_match( '/^(\d{4})/', $publishedAt, $m ) === 1 ? (int)$m[1] : null;
	}

	private static function extractVideoId( string $url ): ?string {
		if ( preg_match( '#[?&]v=([A-Za-z0-9_-]{11})#', $url, $m ) === 1 ) {
			return $m[1];
		}
		if ( preg_match( '#youtu\.be/([A-Za-z0-9_-]{11})#', $url, $m ) === 1 ) {
			return $m[1];
		}
		if ( preg_match( '#/(?:shorts|embed|live)/([A-Za-z0-9_-]{11})#', $url, $m ) === 1 ) {
			return $m[1];
		}
		return null;
	}

	private static function extractChannelId( string $url ): ?string {
		if ( preg_match( '#/channel/([A-Za-z0-9_-]{1,64})#', $url, $m ) === 1 ) {
			return $m[1];
		}
		return null;
	}

	private static function extractHandle( string $url ): ?string {
		if ( preg_match( '#/@([A-Za-z0-9._-]{1,64})#', $url, $m ) === 1 ) {
			return $m[1];
		}
		return null;
	}
}
