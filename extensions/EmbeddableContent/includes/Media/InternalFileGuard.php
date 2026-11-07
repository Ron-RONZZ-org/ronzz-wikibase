<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Media;

use EmbeddableContent\Spec\InternalFile;
use MediaWiki\Context\RequestContext;
use MediaWiki\FileRepo\File\File;
use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\LinkTarget;
use MediaWiki\Title\Title;

/**
 * Anonymous readers must not see an internal file's bytes. `[[File:…]]`
 * renders a styled placeholder with the copyright/login message instead of an
 * `<img>` — no broken image and no media request. Logged-in readers get the
 * normal image (their signed media cookie passes the nginx gate).
 *
 * The placeholder is user-dependent, so an internal file marks the parser
 * output uncacheable: the shared parser cache must never serve an anonymous
 * placeholder to a logged-in reader (or the reverse).
 *
 * @license GPL-2.0-or-later
 */
final class InternalFileGuard {

	/**
	 * ImageBeforeProduceHTML handler, registered BEFORE the image-credit
	 * handler so a blocked internal image never gets a credit line.
	 *
	 * @param mixed $skin
	 * @param mixed $title Title or LinkTarget
	 * @param mixed $file
	 * @param array<string,mixed> &$frameParams
	 * @param array<string,mixed> &$handlerParams
	 * @param mixed $time
	 * @param mixed &$res
	 * @param mixed $parser
	 * @param mixed $query
	 * @param mixed $widthOption
	 * @return bool false to stop the default image rendering
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
		$fileTitle = self::resolveTitle( $title, $file );
		if ( $fileTitle === null || !InternalFile::isInternal( $fileTitle ) ) {
			return true;
		}

		// The rendered output depends on the reader — never share it.
		$parser->getOutput()->updateCacheExpiry( 0 );

		$user = RequestContext::getMain()->getUser();
		if ( !$user->isAnon() ) {
			return true;
		}

		$language = $parser->getTargetLanguage();
		$message = wfMessage( 'embeddablecontent-internal-file-loginrequired' )
			->inLanguage( $language )
			->text();
		$pageTitle = $parser->getTitle();
		$login = Html::element(
			'a',
			[ 'href' => InternalFile::loginUrl( $pageTitle ) ],
			wfMessage( 'login' )->inLanguage( $language )->text()
		);
		$res = Html::warningBox(
			htmlspecialchars( $message ) . ' ' . $login,
			'wb-internal-file-placeholder'
		);
		$parser->getOutput()->addModuleStyles( [
			'mediawiki.codex.messagebox.styles',
			'ext.embeddableContent.internalfile',
		] );
		return false;
	}

	/** @param mixed $title @param mixed $file */
	private static function resolveTitle( $title, $file ): ?Title {
		if ( $title instanceof Title ) {
			return $title;
		}
		if ( $title instanceof LinkTarget ) {
			return Title::newFromLinkTarget( $title );
		}
		if ( $file instanceof File ) {
			return $file->getTitle();
		}
		return null;
	}

}
