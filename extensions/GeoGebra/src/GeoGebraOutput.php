<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\GeoGebra;

use MediaWiki\FileRepo\File\File;
use MediaWiki\Html\Html;
use MediaWiki\Media\MediaTransformOutput;

/**
 * Client-side transform output: the sandboxed player iframe.
 *
 * @license GPL-2.0-or-later
 */
class GeoGebraOutput extends MediaTransformOutput {

	private string $playerUrl;
	private string $sandbox;
	private string $app;

	public function __construct(
		File $file,
		string $playerUrl,
		int $width,
		int $height,
		string $sandbox,
		string $app
	) {
		$this->file = $file;
		$this->url = $file->getFullUrl();
		$this->width = $width;
		$this->height = $height;
		$this->playerUrl = $playerUrl;
		$this->sandbox = $sandbox;
		$this->app = $app;
	}

	/** @inheritDoc */
	public function toHtml( $options = [] ) {
		if ( $this->playerUrl === '' ) {
			// No player configured — degrade to a plain file link.
			return Html::element(
				'a',
				[ 'href' => $this->file->getFullUrl(), 'class' => 'ggb-embed ggb-embed-fallback' ],
				wfMessage( 'geogebra-open-file' )->text()
			);
		}
		// getFullUrl(), not getUrl(): the player origin fetches the file
		// cross-origin, so the URL must be absolute (getUrl() is relative when
		// $wgUploadPath is — e.g. /w/images on the WBS stack).
		return Html::element( 'iframe', GeoGebraEmbed::iframeAttributes(
			$this->playerUrl,
			$this->file->getFullUrl(),
			$this->width,
			$this->height,
			$this->sandbox,
			$this->app
		) );
	}
}
