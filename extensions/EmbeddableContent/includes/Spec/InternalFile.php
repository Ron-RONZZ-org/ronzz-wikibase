<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Content\TextContent;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebResponse;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

/**
 * "Internal use only" access files (copyright-restricted copies).
 *
 * The authoritative flag is a filesystem MARKER per file (not a wiki page):
 * nginx checks it with a plain `stat()`, so an internal file costs no PHP
 * request. The marker path mirrors the local repo layout:
 *
 *   <markerDir>/images/<hash>/<name>
 *
 * One marker covers the original, every thumbnail, archive version and
 * transcoded rendition of the file (nginx derives the same path from the
 * request URI). `Category:Internal use only files` on the File: page is the
 * human-readable marker; `maintenance/syncInternalFileMarkers.php` rebuilds
 * the marker directory from it after a restore.
 *
 * Logged-in users carry a short-lived signed cookie (`wb_media_auth`,
 * `exp.uid.base64url(md5("expuid secret"))`) that nginx validates with
 * `secure_link`; anonymous users have no cookie and are refused. The cookie
 * is set on every page view and cleared on logout.
 *
 * @license GPL-2.0-or-later
 */
final class InternalFile {

	/** Cookie carrying the media-auth signature. */
	public const COOKIE_NAME = 'wb_media_auth';

	/** Cookie lifetime in seconds (refreshed on every page view). */
	public const COOKIE_TTL = 3600;

	/** Default category name (overridable via config). */
	public const DEFAULT_CATEGORY = 'Internal use only files';

	/** The regex special characters a category name may not contain. */
	private const CATEGORY_FORBIDDEN = '/[\[\]|{}<>#]/';

	// ---------------------------------------------------------------- pure

	/**
	 * The marker path relative to the marker root (mirrors the repo's public
	 * zone path). The image prefix keeps the marker tree distinct from any
	 * future non-media markers.
	 */
	public static function markerRelPath( string $rel ): string {
		return 'images/' . ltrim( $rel, '/' );
	}

	/**
	 * The signed media cookie value: `<exp>.<uid>.<base64url(md5(exp + uid +
	 * " " + secret))>`. The separator is `.` because the base64url alphabet
	 * contains `_` and `-`.
	 */
	public static function cookieValue( int $exp, int $uid, string $secret ): string {
		return $exp . '.' . $uid . '.' . self::signature( $exp, $uid, $secret );
	}

	/**
	 * The signature nginx's `secure_link_md5 "$exp$uid $secret"` verifies.
	 * Base64url without padding (the nginx secure_link encoding).
	 */
	public static function signature( int $exp, int $uid, string $secret ): string {
		$raw = md5( $exp . $uid . ' ' . $secret, true );
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * A marker root must be configured and must NOT live inside the web root
	 * or the public upload directory — otherwise the gate is pointless (the
	 * markers themselves are harmless, but a misconfiguration signals the
	 * wrong directory and hides a leak).
	 */
	public static function isSafeMarkerRoot( string $root, string $uploadDir, string $installDir ): bool {
		$root = self::normalizePath( $root );
		if ( $root === '' ) {
			return false;
		}
		foreach ( [ $uploadDir, $installDir ] as $forbidden ) {
			$forbidden = self::normalizePath( $forbidden );
			if ( $forbidden === '' ) {
				continue;
			}
			if ( $root === $forbidden || str_starts_with( $root, $forbidden . '/' ) ) {
				return false;
			}
		}
		return true;
	}

	private static function normalizePath( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		return rtrim( $path, '/' );
	}

	// -------------------------------------------------------------- config

	public static function categoryName(): string {
		$name = (string)MediaWikiServices::getInstance()->getMainConfig()
			->get( 'EmbeddableContentInternalFileCategory' );
		$name = trim( $name );
		if ( $name === '' || preg_match( self::CATEGORY_FORBIDDEN, $name ) === 1 ) {
			return self::DEFAULT_CATEGORY;
		}
		return $name;
	}

	/** The media-auth secret (empty when the feature is not configured). */
	public static function secret(): string {
		return trim( (string)MediaWikiServices::getInstance()->getMainConfig()
			->get( 'EmbeddableContentMediaAuthSecret' ) );
	}

	/**
	 * The marker root. Throws when it is not configured or is unsafe — the
	 * write paths (marking a file) must fail loudly rather than mark a file
	 * the nginx gate cannot see.
	 *
	 * @throws \RuntimeException
	 */
	public static function markerRoot(): string {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$root = (string)$config->get( 'EmbeddableContentMediaMarkerDirectory' );
		$uploadDir = (string)$config->get( 'UploadDirectory' );
		$installDir = $GLOBALS['IP'] ?? '';
		if ( !self::isSafeMarkerRoot( $root, $uploadDir, $installDir ) ) {
			throw new \RuntimeException(
				'EmbeddableContentMediaMarkerDirectory must be configured outside the web root'
				. ' and the upload directory.'
			);
		}
		return self::normalizePath( $root );
	}

	// --------------------------------------------------------------- state

	/**
	 * Whether a File: page is marked internal. The marker is authoritative;
	 * a missing marker directory config is logged and treated as "not
	 * internal" so read-only page rendering never breaks.
	 */
	public static function isInternal( Title $title ): bool {
		try {
			if ( !$title->inNamespace( NS_FILE ) ) {
				return false;
			}
			// The marker path is derivable from the title — no file/DB
			// lookup per image (this runs for every [[File:…]] on a page).
			$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
			$name = $title->getDBkey();
			$rel = $repo->getHashPath( $name ) . $name;
			return is_file( self::markerRoot() . '/' . self::markerRelPath( $rel ) );
		} catch ( \Throwable $e ) {
			wfLogWarning( 'EmbeddableContent: internal-file marker lookup failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Marks or unmarks a file, keeping the category and the nginx marker in
	 * sync. Safe order: for "internal" the category is written first, then
	 * the marker; for "public" the marker is removed first, then the category
	 * — a failure can only leave the file public, never leak it.
	 *
	 * @throws \RuntimeException when the marker directory or the page edit fails
	 */
	public static function setInternal( Title $title, bool $internal, User $user ): void {
		if ( $internal ) {
			self::setCategory( $title, true, $user );
			$path = self::markerPathFor( $title );
			if ( $path === null ) {
				throw new \RuntimeException( 'The file does not exist, so it cannot be marked internal.' );
			}
			self::ensureDirectory( dirname( $path ) );
			if ( file_put_contents( $path, "1\n" ) === false ) {
				throw new \RuntimeException( 'Could not write the internal-file marker.' );
			}
			@chmod( $path, 0664 );
			return;
		}

		$path = self::markerPathFor( $title );
		if ( $path !== null && is_file( $path ) && !unlink( $path ) ) {
			throw new \RuntimeException( 'Could not remove the internal-file marker.' );
		}
		self::setCategory( $title, false, $user );
	}

	/**
	 * Removes the marker for a deleted file (FileDeleteComplete). A marker
	 * left behind would only over-restrict (403), never leak, but it would
	 * also gate a same-named future file — clean it up.
	 *
	 * @param \MediaWiki\FileRepo\File\File $file
	 */
	public static function removeMarkerForFile( $file ): void {
		$path = self::markerRoot() . '/' . self::markerRelPath( $file->getRel() );
		if ( is_file( $path ) && !unlink( $path ) ) {
			throw new \RuntimeException( 'Could not remove the internal-file marker.' );
		}
	}

	/**
	 * Rebuilds the marker for a file from its category state (the restore
	 * path / maintenance script). Only creates markers; never removes one.
	 */
	public static function syncOne( Title $title ): bool {
		$file = self::localFile( $title );
		if ( $file === null ) {
			return false;
		}
		$categories = $title->getParentCategories();
		$category = 'Category:' . self::categoryName();
		if ( !array_key_exists( $category, $categories ) ) {
			return false;
		}
		$path = self::markerRoot() . '/' . self::markerRelPath( $file->getRel() );
		self::ensureDirectory( dirname( $path ) );
		return file_put_contents( $path, "1\n" ) !== false;
	}

	/** @return \MediaWiki\FileRepo\File\File|null */
	private static function localFile( Title $title ) {
		if ( !$title->inNamespace( NS_FILE ) ) {
			return null;
		}
		$file = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $title );
		return ( $file !== false && $file->exists() ) ? $file : null;
	}

	private static function markerPathFor( Title $title ): ?string {
		$file = self::localFile( $title );
		if ( $file === null ) {
			return null;
		}
		return self::markerRoot() . '/' . self::markerRelPath( $file->getRel() );
	}

	private static function ensureDirectory( string $dir ): void {
		if ( is_dir( $dir ) ) {
			return;
		}
		if ( !mkdir( $dir, 0775, true ) && !is_dir( $dir ) ) {
			throw new \RuntimeException( "Could not create the marker directory {$dir}." );
		}
	}

	/**
	 * Adds/removes the category line on the File: page. No-op when the page
	 * already has the wanted state (the AddSource upload writes it directly).
	 */
	private static function setCategory( Title $title, bool $present, User $user ): void {
		$category = self::categoryName();
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		if ( !$page->exists() ) {
			return;
		}
		$content = $page->getContent();
		if ( !$content instanceof TextContent ) {
			return;
		}
		$text = $content->getText();
		$line = '[[Category:' . $category . ']]';
		if ( str_contains( $text, $line ) === $present ) {
			return;
		}
		if ( $present ) {
			$text = rtrim( $text ) . "\n\n" . $line . "\n";
		} else {
			$pattern = '/^[ \t]*\[\[Category:' . preg_quote( $category, '/' ) . '\]\][ \t]*\r?\n?/m';
			$text = trim( (string)preg_replace( $pattern, '', $text ) );
		}
		$summary = wfMessage(
			$present
				? 'embeddablecontent-internal-file-category-summary-add'
				: 'embeddablecontent-internal-file-category-summary-remove'
		)->inContentLanguage()->text();
		$status = $page->doUserEditContent(
			new WikitextContent( $text ),
			$user,
			$summary,
			EDIT_UPDATE,
			$page->getLatest() ?: false
		);
		if ( !$status->isOK() ) {
			throw new \RuntimeException(
				'The file page category could not be updated: ' . $status->getMessage()->text()
			);
		}
	}

	// --------------------------------------------------------------- cookie

	/** Sets/refreshes the signed media cookie for a logged-in user. */
	public static function setMediaCookie( WebResponse $response, User $user ): void {
		$secret = self::secret();
		if ( $secret === '' || !$user->isRegistered() ) {
			return;
		}
		$exp = time() + self::COOKIE_TTL;
		$response->setCookie(
			self::COOKIE_NAME,
			self::cookieValue( $exp, $user->getId(), $secret ),
			$exp,
			self::cookieOptions()
		);
	}

	/** Clears the media cookie (logout). */
	public static function clearMediaCookie( WebResponse $response ): void {
		$response->setCookie( self::COOKIE_NAME, '', time() - self::COOKIE_TTL, self::cookieOptions() );
	}

	/** @return array<string,mixed> */
	private static function cookieOptions(): array {
		$request = RequestContext::getMain()->getRequest();
		return [
			'prefix' => '',
			'path' => '/',
			'secure' => $request->getProtocol() === 'https',
			'httpOnly' => true,
			'sameSite' => 'lax',
			'raw' => true,
		];
	}

	// --------------------------------------------------------------- misc

	/**
	 * The "All rights reserved" licence item id, resolved from the seed's
	 * licence map by label (no hardcoded Q-id). Null when the preseed item
	 * is missing — the caller surfaces a configuration error.
	 */
	public static function allRightsReservedLicenseId( EmbeddableContentConfig $config ): ?string {
		foreach ( $config->licenseItems() as $label => $id ) {
			if ( strtolower( trim( (string)$label ) ) === 'all rights reserved'
				&& preg_match( '/^Q[1-9]\d*$/', (string)$id ) === 1
			) {
				return (string)$id;
			}
		}
		return null;
	}

	/** Login URL returning to a page, or the plain login page. */
	public static function loginUrl( ?Title $returnto = null ): string {
		$params = $returnto !== null ? [ 'returnto' => $returnto->getPrefixedText() ] : [];
		return SpecialPage::getTitleFor( 'UserLogin' )->getFullURL( $params );
	}

}
