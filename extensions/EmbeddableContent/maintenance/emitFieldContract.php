<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- entry point name

declare( strict_types = 1 );

// A PURE script (no MediaWiki bootstrap): the Flow/*FieldMap classes and
// FieldContract carry no MediaWiki runtime dependency, so the canonical field
// contract can be (re)emitted from any PHP install. Run it after changing a
// field map, and commit the result — FieldContractTest fails otherwise.
//
//   php extensions/EmbeddableContent/maintenance/emitFieldContract.php
//
// An optional first argument overrides the output path.

$root = dirname( __DIR__ );
require $root . '/includes/Flow/SpecialContentFieldMap.php';
require $root . '/includes/Flow/SourceFieldMap.php';
require $root . '/includes/Flow/SemanticEntityFieldMap.php';
require $root . '/includes/Flow/FieldContract.php';

$out = $argv[1] ?? $root . '/contract/field-contract.json';
$dir = dirname( $out );
if ( !is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}
file_put_contents( $out, \EmbeddableContent\Flow\FieldContract::toJson() );
fwrite( STDOUT, "wrote {$out}\n" );
