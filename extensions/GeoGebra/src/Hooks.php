<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\GeoGebra;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Parser\Parser;

/**
 * MIME registration for .ggb files + the `<geogebra>` parser tag.
 *
 * A .ggb is a renamed ZIP (containing geogebra.xml), so the content sniffer
 * detects it as application/zip. Two hooks are needed:
 *  - MimeMagicInit teaches the analyzer the extension <-> MIME pair and maps
 *    the MIME to a media type (DRAWING — the closest core type, so
 *    [[File:x.ggb]] embeds rather than links);
 *  - MimeMagicImproveFromExtension rewrites the ZIP-detected type back to
 *    application/geogebra for .ggb uploads.
 *
 * The `<geogebra>` tag renders RAW GeoGebra commands (one per line) without
 * an uploaded .ggb: the commands ride the sandboxed player iframe and are
 * evaluated client-side by the GeoGebra applet.
 *
 * @license GPL-2.0-or-later
 */
final class Hooks {

	/**
	 * @param \Wikimedia\Mime\MimeAnalyzer $mimeMagic
	 */
	public static function onMimeMagicInit( $mimeMagic ): void {
		$mimeMagic->addExtraTypes( GeoGebraEmbed::MIME . ' ' . GeoGebraEmbed::EXTENSION );
		// "[MEDIATYPE]\nMIME" — registers application/geogebra as a DRAWING.
		$mimeMagic->addExtraInfo( "[DRAWING]\n" . GeoGebraEmbed::MIME );
	}

	/**
	 * @param \Wikimedia\Mime\MimeAnalyzer $mimeMagic
	 * @param string $ext
	 * @param string &$mime
	 */
	public static function onMimeMagicImproveFromExtension( $mimeMagic, $ext, &$mime ): void {
		if ( strtolower( (string)$ext ) === GeoGebraEmbed::EXTENSION ) {
			$mime = GeoGebraEmbed::MIME;
		}
	}

	/**
	 * Register the `<geogebra>` tag.
	 */
	public static function onParserFirstCallInit( Parser $parser ): void {
		$parser->setHook( 'geogebra', [ self::class, 'renderGeoGebra' ] );
	}

	/**
	 * Render `<geogebra>command per line</geogebra>` as a sandboxed player
	 * iframe carrying the commands. Attributes: `width`, `height`, `app`.
	 *
	 * @param string|null $input the raw GeoGebra commands
	 * @param array<string,string> $args the tag attributes
	 * @return string HTML
	 */
	public static function renderGeoGebra( $input, array $args, Parser $parser, PPFrame $frame ): string {
		$config = MediaWikiServices::getInstance()->getMainConfig();

		if ( !$config->get( 'GeoGebraEnableCommands' ) ) {
			return self::errorBox( 'geogebra-commands-disabled' );
		}

		$commands = trim( (string)$input );
		if ( $commands === '' ) {
			return self::errorBox( 'geogebra-empty' );
		}
		$maxLength = (int)$config->get( 'GeoGebraCommandMaxLength' );
		if ( $maxLength > 0 && strlen( $commands ) > $maxLength ) {
			return self::errorBox( 'geogebra-too-long', $maxLength );
		}

		$playerUrl = (string)$config->get( 'GeoGebraPlayerUrl' );
		if ( $playerUrl === '' ) {
			return self::errorBox( 'geogebra-no-player' );
		}

		[ $width, $height ] = self::sizeFromArgs( $args, $config );

		$parser->getOutput()->addModuleStyles( [ 'ext.geogebra.styles' ] );

		return Html::element( 'iframe', GeoGebraEmbed::commandIframeAttributes(
			$playerUrl,
			$commands,
			$width,
			$height,
			(string)$config->get( 'GeoGebraIframeSandbox' ),
			self::appFromArgs( $args, $config )
		) );
	}

	/**
	 * The width/height from the tag attributes, falling back to the configured
	 * defaults and preserving the default aspect ratio when only a width is
	 * given (the media handler's `normaliseParams` contract).
	 *
	 * @param array<string,string> $args
	 * @param \MediaWiki\Config\Config $config
	 * @return int[] [ width, height ]
	 */
	private static function sizeFromArgs( array $args, $config ): array {
		$defaultWidth = max( 1, (int)$config->get( 'GeoGebraDefaultWidth' ) );
		$defaultHeight = max( 1, (int)$config->get( 'GeoGebraDefaultHeight' ) );

		$width = self::positiveInt( $args['width'] ?? null );
		$height = self::positiveInt( $args['height'] ?? null );

		if ( $width <= 0 ) {
			$width = $defaultWidth;
			$height = $height > 0 ? $height : $defaultHeight;
		} elseif ( $height <= 0 ) {
			$height = (int)round( $width * $defaultHeight / $defaultWidth );
		}
		return [ $width, $height ];
	}

	/** @param string|null $value */
	private static function positiveInt( ?string $value ): int {
		if ( $value !== null && preg_match( '/^\d+$/', trim( $value ) ) ) {
			return (int)trim( $value );
		}
		return 0;
	}

	/**
	 * The app name from the `app` attribute (sanitized to an identifier), or
	 * the configured default.
	 *
	 * @param array<string,string> $args
	 * @param \MediaWiki\Config\Config $config
	 */
	private static function appFromArgs( array $args, $config ): string {
		$app = isset( $args['app'] )
			? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $args['app'] ) )
			: '';
		return $app !== '' ? $app : (string)$config->get( 'GeoGebraDefaultApp' );
	}

	/** An escaped inline error notice (never a broken/blank iframe). */
	private static function errorBox( string $messageKey, ...$params ): string {
		return Html::element(
			'span',
			[ 'class' => 'error ggb-embed-error' ],
			wfMessage( $messageKey, ...$params )->text()
		);
	}
}
