<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

/**
 * Registration-time hooks (issue #6 §7).
 *
 * @license GPL-2.0-or-later
 */
class Hooks {

	/**
	 * Wire the extension's own composer vendor autoloader into MediaWiki.
	 *
	 * The instance deployment installs composer deps inside the extension
	 * directory (not at the wiki root via the merge plugin), so the root
	 * autoloader knows nothing about seboettg/citeproc-php — without this,
	 * every apa/vancouver citation request fatals with
	 * 'Class "Seboettg\CiteProc\CiteProc" not found'. No-op when the vendor
	 * dir is absent (e.g. composer install at the wiki root was used).
	 */
	public static function onRegistration(): void {
		$autoload = __DIR__ . '/../vendor/autoload.php';
		if ( is_file( $autoload ) ) {
			require_once $autoload;
		}
	}
}
