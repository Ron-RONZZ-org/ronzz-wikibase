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
 * Guards the house extensions against instantiating PropertyId directly.
 *
 * The deployed Wikibase bundle makes PropertyId an interface (only
 * NumericPropertyId is instantiable), but the unit-test vendor pins
 * wikibase/data-model 9.6.1, where PropertyId is still a concrete class. A
 * stray `new PropertyId()` therefore passes the whole PHPUnit suite and only
 * 500s on production. LocalAuthorityResolver did exactly that (the
 * embeddable-authority-search harvest failure); every site must go through
 * PropertyIdFactory.
 *
 * Vendored third-party extensions are upstream code and deliberately not
 * audited here.
 *
 * @license GPL-2.0-or-later
 */
final class PropertyIdInstantiationTest extends TestCase {

	/** The one file allowed to hold the concrete-vs-interface fallback. */
	private const FACTORY_FILE = 'extensions/EmbeddableContent/includes/PropertyIdFactory.php';

	/** Vendored upstream extensions — never audited. */
	private const VENDORED_EXTENSIONS = [ 'Diagrams', 'DPLforum', 'InputBox', 'SimpleMathJax' ];

	/** Directories that hold third-party code inside a house extension. */
	private const SKIPPED_DIRECTORIES = [ 'vendor', 'node_modules' ];

	public function testHouseExtensionsConstructPropertyIdsThroughTheFactory(): void {
		$root = realpath( __DIR__ . '/../..' );
		$this->assertIsString( $root, 'cannot resolve the repository root' );

		$files = self::housePhpFiles( $root . '/extensions' );
		$this->assertNotSame( [], $files, 'no house-extension PHP files found to audit' );

		$violations = [];
		foreach ( $files as $file ) {
			$relative = substr( $file, strlen( $root ) + 1 );
			if ( $relative === self::FACTORY_FILE ) {
				continue;
			}
			$source = file_get_contents( $file );
			$this->assertIsString( $source, "cannot read $file" );
			if ( preg_match( '/new\s+(?:\\\\?Wikibase\\\\DataModel\\\\Entity\\\\)?PropertyId\s*\(/', $source ) === 1 ) {
				$violations[] = $relative . ' → use PropertyIdFactory::create()';
			}
		}

		$this->assertSame(
			[],
			$violations,
			"Direct PropertyId instantiation in house extensions:\n" . implode( "\n", $violations )
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
					if ( $current->isDir() ) {
						$name = $current->getFilename();
						if ( in_array( $name, self::SKIPPED_DIRECTORIES, true ) ) {
							return false;
						}
						if ( dirname( $current->getPathname() ) === $extensionsDir
							&& in_array( $name, self::VENDORED_EXTENSIONS, true )
						) {
							return false;
						}
					}
					return true;
				}
			)
		);

		$files = [];
		foreach ( $iterator as $file ) {
			if ( $file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php' ) {
				$files[] = $file->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

}
