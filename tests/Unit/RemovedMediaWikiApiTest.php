<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the house extensions against calling MediaWiki APIs that no longer
 * exist on the runtime we deploy (MediaWiki 1.46).
 *
 * A removed method is invisible to the pure-PHP unit suite (it never
 * instantiates a Parser) and only surfaces as a production 500 when a page
 * happens to hit that code path. `Parser::getFunctionLang()` was deprecated
 * in MW 1.40 ("use ::getTargetLanguage() instead") and removed in MW 1.46,
 * which fataled every Source: page whose item has child items
 * (ChildItemsOf::rowLabel()). This scan fails CI on that class of
 * regression instead of waiting for a page to report it.
 *
 * Vendored third-party extensions (Diagrams, DPLforum, InputBox,
 * SimpleMathJax) are upstream code and deliberately not audited here.
 *
 * @license GPL-2.0-or-later
 */
final class RemovedMediaWikiApiTest extends TestCase {

	/** Removed call pattern => the replacement to use instead. */
	private const REMOVED_CALLS = [
		'/->getFunctionLang\s*\(/'
			=> 'Parser::getTargetLanguage() (getFunctionLang was removed in MW 1.46)',
	];

	/** Vendored upstream extensions — never audited. */
	private const VENDORED_EXTENSIONS = [ 'Diagrams', 'DPLforum', 'InputBox', 'SimpleMathJax' ];

	/** Directories that hold third-party code inside a house extension. */
	private const SKIPPED_DIRECTORIES = [ 'vendor', 'node_modules' ];

	public function testHouseExtensionsCallOnlyCurrentMediaWikiApis(): void {
		$root = realpath( __DIR__ . '/../..' );
		$this->assertIsString( $root, 'cannot resolve the repository root' );

		$files = self::housePhpFiles( $root . '/extensions' );
		$this->assertNotSame( [], $files, 'no house-extension PHP files found to audit' );

		$violations = [];
		foreach ( $files as $file ) {
			$source = file_get_contents( $file );
			$this->assertIsString( $source, "cannot read $file" );
			foreach ( self::REMOVED_CALLS as $pattern => $replacement ) {
				if ( preg_match( $pattern, $source ) === 1 ) {
					$violations[] = substr( $file, strlen( $root ) + 1 ) . " → use $replacement";
				}
			}
		}

		$this->assertSame(
			[],
			$violations,
			"Removed MediaWiki APIs in house extensions:\n" . implode( "\n", $violations )
		);
	}

	/**
	 * @return string[] absolute paths of the house-extension PHP files
	 */
	private static function housePhpFiles( string $extensionsDir ): array {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $extensionsDir, FilesystemIterator::SKIP_DOTS ),
				static function ( SplFileInfo $current ) use ( $extensionsDir ): bool {
					$name = $current->getFilename();
					if ( $current->isDir() ) {
						if ( in_array( $name, self::SKIPPED_DIRECTORIES, true ) ) {
							return false;
						}
						$relative = substr( $current->getPathname(), strlen( $extensionsDir ) + 1 );
						return !in_array( $relative, self::VENDORED_EXTENSIONS, true );
					}
					return str_ends_with( $name, '.php' );
				}
			)
		);

		$files = [];
		foreach ( $iterator as $file ) {
			$files[] = $file->getPathname();
		}
		sort( $files );

		return $files;
	}
}
