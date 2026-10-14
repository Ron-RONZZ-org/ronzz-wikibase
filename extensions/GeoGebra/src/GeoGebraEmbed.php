<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\GeoGebra;

/**
 * Pure helpers for building the sandboxed player embed.
 *
 * Two flavours share one iframe contract:
 *  - a `.ggb` FILE (`[[File:x.ggb]]`, the media handler) — the player fetches
 *    the file cross-origin (CORS);
 *  - RAW GeoGebra COMMANDS (the `<geogebra>` parser tag) — the commands are
 *    carried in the URL and evaluated by the player's applet
 *    (`evalCommand`), so no upload/`.ggb` is needed.
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
		return self::playerUrlWith( $playerUrl, [
			'file' => $fileUrl,
			'w' => $width,
			'h' => $height,
			'app' => $app,
		] );
	}

	/**
	 * Build the player URL for RAW GeoGebra commands. The commands are
	 * percent-encoded into the `commands` query parameter; the player splits
	 * them on newlines and evaluates each through the applet's `evalCommand`.
	 */
	public static function commandPlayerSrc(
		string $playerUrl,
		string $commands,
		int $width,
		int $height,
		string $app
	): string {
		return self::playerUrlWith( $playerUrl, [
			'commands' => $commands,
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
		return self::frameAttributes(
			self::playerSrc( $playerUrl, $fileUrl, $width, $height, $app ),
			$width,
			$height,
			$sandbox
		);
	}

	/**
	 * The iframe attributes for RAW GeoGebra commands (the `<geogebra>` tag).
	 *
	 * @return array<string,string>
	 */
	public static function commandIframeAttributes(
		string $playerUrl,
		string $commands,
		int $width,
		int $height,
		string $sandbox,
		string $app
	): array {
		return self::frameAttributes(
			self::commandPlayerSrc( $playerUrl, $commands, $width, $height, $app ),
			$width,
			$height,
			$sandbox
		);
	}

	/**
	 * @param array<string,int|string> $params
	 */
	private static function playerUrlWith( string $playerUrl, array $params ): string {
		$separator = str_contains( $playerUrl, '?' ) ? '&' : '?';
		return $playerUrl . $separator . http_build_query( $params );
	}

	/**
	 * The shared sandboxed-iframe contract (file and command embeds alike).
	 *
	 * @return array<string,string>
	 */
	private static function frameAttributes(
		string $src,
		int $width,
		int $height,
		string $sandbox
	): array {
		return [
			'class' => 'ggb-embed',
			'src' => $src,
			'width' => (string)$width,
			'height' => (string)$height,
			'sandbox' => $sandbox,
			'allow' => 'fullscreen',
			'loading' => 'lazy',
			'referrerpolicy' => 'no-referrer',
			'frameborder' => '0',
			'title' => 'GeoGebra',
		];
	}
}
