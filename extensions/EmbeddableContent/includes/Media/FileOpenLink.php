<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Media;

use MediaWiki\FileRepo\File\File;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\LinkTarget;

/**
 * The "Open in a new tab" link inside a framed/thumbnail caption.
 *
 * Content-page embeds that have a caption area (thumb / framed / manualthumb)
 * get the link appended to the caption here, so the affordance exists without
 * JavaScript. Captionless images, audio/video players, PDF link embeds and
 * galleries are handled client-side by resources/mediaopen.js (the server
 * cannot reliably locate every rendered media element without rewrites).
 *
 * Internal-use files are already stopped by Media\InternalFileGuard (the hook
 * chain aborts before this handler), so an anonymous reader never gets a link
 * to a gated media URL.
 *
 * @license GPL-2.0-or-later
 */
final class FileOpenLink {

	/**
	 * ImageBeforeProduceHTML handler (registered after imagecredit, so the
	 * link follows the credit line).
	 *
	 * @param mixed $skin unused
	 * @param mixed $title LinkTarget/Title of the File: page
	 * @param mixed $file File|false
	 * @param array<string,mixed> $frameParams frame parameters (caption is modified)
	 * @param array<string,mixed> $handlerParams unused
	 * @param mixed $time unused
	 * @param mixed $res unused at hook entry
	 * @param mixed $parser Parser
	 * @param mixed $query unused
	 * @param mixed $widthOption unused
	 */
	public static function onImageBeforeProduceHTML(
		$skin,
		$title,
		$file,
		array &$frameParams,
		array &$handlerParams,
		$time,
		&$res,
		$parser,
		$query,
		$widthOption
	): bool {
		if ( !$parser instanceof Parser ) {
			return true;
		}
		$pageTitle = $parser->getTitle();
		if ( $pageTitle === null || !$pageTitle->isContentPage() ) {
			return true;
		}
		// Only the caption-bearing forms: core has nowhere to put the link on
		// a plain image, and Media\InternalFileGuard already handled gating.
		$hasCaptionArea = isset( $frameParams['thumbnail'] )
			|| isset( $frameParams['manualthumb'] )
			|| isset( $frameParams['framed'] );
		if ( !$hasCaptionArea ) {
			return true;
		}
		if ( !$file instanceof File || !$file->exists() ) {
			return true;
		}
		$url = $file->getFullUrl();
		if ( $url === '' ) {
			return true;
		}
		$label = wfMessage( 'embeddablecontent-file-open-newtab' )
			->inLanguage( $parser->getTargetLanguage() )
			->text();
		$link = \EmbeddableContent\Spec\MediaOpenLink::html( $url, $label );
		$frameParams['caption'] = (string)( $frameParams['caption'] ?? '' ) . $link;
		$parser->getOutput()->addModuleStyles( [ 'ext.embeddableContent.fileviewer' ] );
		return true;
	}

}
