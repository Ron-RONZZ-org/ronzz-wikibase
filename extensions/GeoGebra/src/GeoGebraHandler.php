<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\GeoGebra;

use MediaWiki\FileRepo\File\File;
use MediaWiki\Media\ImageHandler;
use MediaWiki\MediaWikiServices;

/**
 * Media handler for GeoGebra worksheets (.ggb).
 *
 * Rendering is CLIENT-SIDE: the handler emits a sandboxed <iframe> pointing
 * at the cookie-less player origin, which loads the self-hosted GeoGebra app
 * and the file. There is no server-side rasterization (the file's embedded
 * geogebra_thumbnail.png is not extracted in v1).
 *
 * @license GPL-2.0-or-later
 */
class GeoGebraHandler extends ImageHandler {

	/** @inheritDoc */
	public function canRender( $file ) {
		// A .ggb has no intrinsic pixel size — always renderable.
		return true;
	}

	/** @inheritDoc */
	public function mustRender( $file ) {
		return true;
	}

	/** @inheritDoc */
	public function isVectorized( $file ) {
		return true;
	}

	/** @inheritDoc */
	public function getImageSize( $image, $path, $metadata = false ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		return [
			(int)$config->get( 'GeoGebraDefaultWidth' ),
			(int)$config->get( 'GeoGebraDefaultHeight' ),
		];
	}

	/** @inheritDoc */
	public function normaliseParams( $image, &$params ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$defaultWidth = (int)$config->get( 'GeoGebraDefaultWidth' );
		$defaultHeight = (int)$config->get( 'GeoGebraDefaultHeight' );

		$width = isset( $params['width'] ) ? (int)$params['width'] : 0;
		$height = isset( $params['height'] ) ? (int)$params['height'] : 0;

		if ( $width <= 0 ) {
			$width = $defaultWidth;
			$height = $height > 0 ? $height : $defaultHeight;
		} elseif ( $height <= 0 ) {
			// Keep the default aspect ratio when only a width was given.
			$height = (int)round( $width * $defaultHeight / $defaultWidth );
		}

		$params['width'] = $width;
		$params['height'] = $height;
		$params['physicalWidth'] = $width;
		$params['physicalHeight'] = $height;
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$width = (int)( $params['width'] ?? $config->get( 'GeoGebraDefaultWidth' ) );
		$height = (int)( $params['height'] ?? $config->get( 'GeoGebraDefaultHeight' ) );
		if ( $width <= 0 ) {
			$width = (int)$config->get( 'GeoGebraDefaultWidth' );
		}
		if ( $height <= 0 ) {
			$height = (int)$config->get( 'GeoGebraDefaultHeight' );
		}
		return new GeoGebraOutput(
			$image,
			(string)$config->get( 'GeoGebraPlayerUrl' ),
			$width,
			$height,
			(string)$config->get( 'GeoGebraIframeSandbox' ),
			(string)$config->get( 'GeoGebraDefaultApp' )
		);
	}

	/** @inheritDoc */
	public function parserTransformHook( $parser, $file ) {
		$parser->getOutput()->addModuleStyles( [ 'ext.geogebra.styles' ] );
	}
}
