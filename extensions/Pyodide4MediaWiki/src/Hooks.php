<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\Pyodide4MediaWiki;

use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;

/**
 * Pyodide4MediaWiki entry-point hooks.
 *
 * The extension only makes a code block executable when the block carries the
 * `pyodide` marker (see PyodideBlockDetector). On such a page the small
 * ResourceLoader bootstrap (`ext.pyodide4mediawiki.run`) is queued and its
 * site-wide config is exported; the heavy Pyodide runtime and the vendored
 * Python packages are fetched from the wiki's own origin only when the reader
 * clicks Run.
 *
 * @license GPL-2.0-or-later
 */
final class Hooks {

	/** ResourceLoader module carrying the Run control + the Pyodide runner. */
	private const RUN_MODULE = 'ext.pyodide4mediawiki.run';

	/**
	 * `ParserAfterTidy` sees the final page HTML, so the marker can be
	 * detected on the assembled output (including transcluded blocks).
	 */
	public static function onParserAfterTidy( Parser $parser, string &$text ): void {
		$config = MediaWikiServices::getInstance()->getMainConfig();

		if ( !$config->get( 'Pyodide4MediaWikiEnable' ) ) {
			return;
		}
		if ( !PyodideBlockDetector::containsPyodideBlock( $text ) ) {
			return;
		}

		$output = $parser->getOutput();
		$output->addModules( [ self::RUN_MODULE ] );

		// Site-wide configuration only: the parser output is cached and shared
		// across users, so nothing user-specific may be exported here. The
		// login gate is applied client-side against the per-user wgUserName.
		$output->setJsConfigVar( 'wgPyodide4MediaWikiIndexUrl', self::assetsUrl( $config ) );
		$output->setJsConfigVar(
			'wgPyodide4MediaWikiPackages',
			array_values( (array)$config->get( 'Pyodide4MediaWikiPackages' ) )
		);
		$output->setJsConfigVar(
			'wgPyodide4MediaWikiRequireLogin',
			(bool)$config->get( 'Pyodide4MediaWikiRequireLogin' )
		);
	}

	/**
	 * The Pyodide distribution directory, served from the wiki's own origin
	 * (installed by tools/install-pyodide.sh — never a runtime CDN).
	 */
	private static function assetsUrl( Config $config ): string {
		$base = (string)$config->get( 'ExtensionAssetsPath' );
		return rtrim( $base, '/' ) . '/Pyodide4MediaWiki/resources/pyodide/';
	}
}
