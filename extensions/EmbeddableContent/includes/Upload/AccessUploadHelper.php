<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Upload;

use EmbeddableContent\Content\FragmentSanitizer;
use EmbeddableContent\Spec\InternalFile;
use MediaWiki\Context\IContextSource;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebRequestUpload;
use MediaWiki\Title\Title;
use MediaWiki\Upload\UploadBase;
use MediaWiki\Upload\UploadFromFile;
use MediaWiki\Upload\UploadFromUrl;
use MediaWiki\User\User;

/**
 * Uploads the access file of a source (PDF/EPUB/DjVu/…): local browser file
 * or fetch-from-URL. Shared by Special:AddSource/UpdateSource (form submit)
 * and api.php?action=accessupload (the multi-access editor's "Fetch" step).
 *
 * The destination name is `<item label>.<ext>` (the original filename is
 * ignored), the File: page text is generated from the item label/description,
 * and a non-public entry gets the InternalFile marker/category.
 *
 * @license GPL-2.0-or-later
 */
final class AccessUploadHelper {

	/**
	 * A browser-supplied file.
	 *
	 * @return Title|null null when there is no file (the caller
	 *   distinguishes "no file" from a failed verification)
	 * @throws \RuntimeException when verification/upload fails
	 */
	public static function uploadFile(
		IContextSource $context,
		User $user,
		WebRequestUpload $upload,
		string $label,
		string $description,
		bool $internal
	): ?Title {
		if ( $upload->getSize() <= 0 || $upload->getTempName() === '' ) {
			return null;
		}
		$tempPath = $upload->getTempName();
		$mime = MediaWikiServices::getInstance()
			->getMimeAnalyzer()->guessMimeType( $tempPath, false );
		$destName = self::destName( $label, $upload->getName(), $mime );
		if ( $destName === '' ) {
			return null;
		}
		$base = new UploadFromFile();
		$base->initializePathInfo( $destName, $tempPath, $upload->getSize() );
		return self::perform( $base, $context, $user, $label, $description, $internal );
	}

	/**
	 * Fetch a direct file URL (UploadFromUrl applies the instance's SSRF
	 * guards and follows redirects). Returns null when the fetch fails.
	 */
	public static function uploadUrl(
		IContextSource $context,
		User $user,
		string $url,
		string $label,
		string $description,
		bool $internal
	): ?Title {
		if ( !UploadFromUrl::isAllowed( $user ) ) {
			return null;
		}
		$url = ( new FragmentSanitizer() )->validateUrl( $url );
		if ( $url === null ) {
			return null;
		}
		$path = parse_url( $url, PHP_URL_PATH );
		$name = $path !== false && $path !== null && $path !== '' ? basename( $path ) : 'file';
		$base = new UploadFromUrl();
		$base->initialize( self::destName( $label, $name, self::mimeFromUrl( $url ) ), $url );
		$status = $base->fetchFile();
		$tempPath = $base->getTempPath();
		if ( !$status->isGood() || $tempPath === '' || !is_file( $tempPath ) ) {
			return null;
		}
		return self::perform( $base, $context, $user, $label, $description, $internal );
	}

	private static function perform(
		UploadBase $base,
		IContextSource $context,
		User $user,
		string $label,
		string $description,
		bool $internal
	): ?Title {
		$title = $base->getTitle();
		if ( $title === null ) {
			return null;
		}
		if ( $title->exists() ) {
			// Idempotent: already uploaded — make sure the marker matches.
			InternalFile::setInternal( $title, $internal, $user );
			return $title;
		}
		$verify = $base->verifyUpload();
		if ( ( $verify['status'] ?? null ) !== UploadBase::OK ) {
			$details = $verify['details'] ?? [];
			$detail = is_array( $details ) && $details !== []
				? (string)( $details[0] ?? '' )
				: (string)( $verify['status'] ?? 'rejected' );
			throw new \RuntimeException( 'verifyUpload rejected (' . $detail . ')' );
		}
		$pageText = $context->msg( 'embeddablecontent-source-access-file-page', $label, $description )->text();
		// The license now lives on the file's access-file item; render it
		// (and any attribution) through the same parser function the
		// Special:Upload File pages use.
		$pageText .= "\n\n{{#image-facts:}}";
		if ( $internal ) {
			$pageText .= "\n\n[[Category:" . InternalFile::categoryName() . "]]\n";
		}
		$status = $base->performUpload(
			$context->msg( 'embeddablecontent-source-access-file-edit-summary', $label )
				->inContentLanguage()->text(),
			$pageText,
			false,
			$user
		);
		if ( !$status->isOK() ) {
			throw new \RuntimeException( 'performUpload rejected' );
		}
		InternalFile::setInternal( $title, $internal, $user );
		return $title;
	}

	/**
	 * Destination name `<label>.<ext>`; the extension comes from the
	 * original name when it is in $wgFileExtensions, else from the MIME
	 * type. Returns '' for an unusable label/format.
	 */
	public static function destName( string $label, string $originalName, string $mime ): string {
		$allowed = MediaWikiServices::getInstance()->getMainConfig()->get( 'FileExtensions' );
		$allowed = is_array( $allowed ) ? array_map( 'strtolower', $allowed ) : [];
		$ext = strtolower( (string)pathinfo( $originalName, PATHINFO_EXTENSION ) );
		if ( !in_array( $ext, $allowed, true ) ) {
			$ext = strtolower( (string)( MediaWikiServices::getInstance()
				->getMimeAnalyzer()->getExtensionFromMimeTypeOrNull( $mime ) ?? '' ) );
		}
		if ( $ext === '' || !in_array( $ext, $allowed, true ) ) {
			return '';
		}
		$label = (string)preg_replace( '/[#<>\[\]|{}:]/', '', trim( $label ) );
		$label = trim( (string)preg_replace( '/\s+/', '-', $label ) );
		return $label === '' ? '' : "{$label}.{$ext}";
	}

	private static function mimeFromUrl( string $url ): string {
		try {
			$http = MediaWikiServices::getInstance()->getHttpRequestFactory()
				->create( $url, [], __METHOD__ );
			$http->execute();
			return (string)$http->getResponseHeader( 'Content-Type' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

}
