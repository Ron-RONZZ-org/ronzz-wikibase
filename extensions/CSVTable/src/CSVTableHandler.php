<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\CSVTable;

use MediaWiki\Media\ImageHandler;
use MediaWiki\MediaWikiServices;

/**
 * Media handler for uploaded CSV files (`text/csv`).
 *
 * A CSV has no intrinsic pixel size and is not an image, but the parser's
 * inline-display gate (`File::allowInlineDisplay()` → the handler's
 * `canRender()`) is what turns `[[File:data.csv]]` into an embed rather than
 * a file link. The handler therefore reports a nominal size and returns a
 * `CSVTableOutput` that renders the parsed table. No thumbnail file is
 * written (the output is HTML built from the source at render time).
 *
 * @license GPL-2.0-or-later
 */
class CSVTableHandler extends ImageHandler {

	/** Nominal size: CSVs are rendered full-width; the parser needs a width. */
	private const NOMINAL_WIDTH = 800;
	private const NOMINAL_HEIGHT = 600;

	/** @inheritDoc */
	public function canRender( $file ) {
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
		return [ self::NOMINAL_WIDTH, self::NOMINAL_HEIGHT ];
	}

	/** @inheritDoc */
	public function normaliseParams( $image, &$params ) {
		$width = isset( $params['width'] ) ? (int)$params['width'] : 0;
		if ( $width <= 0 ) {
			$width = self::NOMINAL_WIDTH;
		}
		$params['width'] = $width;
		$params['height'] = (int)( $params['height'] ?? self::NOMINAL_HEIGHT );
		$params['physicalWidth'] = $width;
		$params['physicalHeight'] = $params['height'];
		return true;
	}

	/** @inheritDoc */
	public function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		return new CSVTableOutput(
			$image,
			(string)$config->get( 'CSVTableDelimiter' ),
			(bool)$config->get( 'CSVTableFirstRowHeader' ),
			(int)$config->get( 'CSVTableMaxBytes' ),
			(int)$config->get( 'CSVTableMaxRows' ),
			(int)$config->get( 'CSVTableMaxCols' )
		);
	}

	/** @inheritDoc */
	public function parserTransformHook( $parser, $file ) {
		$parser->getOutput()->addModuleStyles( [ 'ext.csvTable.styles' ] );
	}
}
