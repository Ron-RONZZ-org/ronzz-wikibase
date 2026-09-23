<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\GeoGebra;

/**
 * MIME registration for .ggb files.
 *
 * A .ggb is a renamed ZIP (containing geogebra.xml), so the content sniffer
 * detects it as application/zip. Two hooks are needed:
 *  - MimeMagicInit teaches the analyzer the extension <-> MIME pair and maps
 *    the MIME to a media type (DRAWING — the closest core type, so
 *    [[File:x.ggb]] embeds rather than links);
 *  - MimeMagicImproveFromExtension rewrites the ZIP-detected type back to
 *    application/geogebra for .ggb uploads.
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
}
