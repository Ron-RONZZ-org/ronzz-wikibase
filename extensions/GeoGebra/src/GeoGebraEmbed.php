<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\GeoGebra;

/**
 * Pure helpers for building the sandboxed player embed.
 *
 * No MediaWiki runtime dependencies — unit-tested by
 * tests/Unit/GeoGebraEmbedTest.php.
 *
 * @license GPL-2.0-or-later
 */
final class GeoGebraEmbed {

	public const MIME = 'application/geogebra';
	public const EXTENSION = 'ggb';

	/**
	 * Build the player URL for a file, carrying the applet parameters as
	 * query parameters. The player fetches the file cross-origin (CORS) from
	 * the wiki.
	 */
	public static function playerSrc(
		string $playerUrl,
		string $fileUrl,
		int $width,
		int $height,
		string $app
	): string {
		$separator = str_contains( $playerUrl, '?' ) ? '&' : '?';
		return $playerUrl . $separator . http_build_query( [
			'file' => $fileUrl,
			'w' => $width,
			'h' => $height,
			'app' => $app,
		] );
	}

	/**
	 * The iframe attributes for a file. Escaping is the caller's job
	 * (Html::element escapes every attribute value).
	 *
	 * @return array<string,string>
	 */
	public static function iframeAttributes(
		string $playerUrl,
		string $fileUrl,
		int $width,
		int $height,
		string $sandbox,
		string $app
	): array {
		return [
			'class' => 'ggb-embed',
			'src' => self::playerSrc( $playerUrl, $fileUrl, $width, $height, $app ),
			'width' => (string)$width,
			'height' => (string)$height,
			'sandbox' => $sandbox,
			'allow' => 'fullscreen',
			'allowfullscreen' => '',
			'loading' => 'lazy',
			'referrerpolicy' => 'no-referrer',
			'frameborder' => '0',
			'title' => 'GeoGebra',
		];
	}
}
