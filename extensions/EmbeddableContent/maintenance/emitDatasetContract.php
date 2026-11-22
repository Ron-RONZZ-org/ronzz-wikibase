<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- entry point name

declare( strict_types = 1 );

// A PURE script (no MediaWiki bootstrap): DatasetSchema carries no MediaWiki
// runtime dependency, so the contract can be re-emitted from any PHP install.
//
//   php extensions/EmbeddableContent/maintenance/emitDatasetContract.php [out-dir]
//
// Writes the generated contract files into <out-dir> (default: the
// extension's contract/ directory) and sql/dataset-schema.sql next to the
// extension. The hand-written contract/dataset-manifest.example.json is read
// to build the envelope example; it is never overwritten.

$root = dirname( __DIR__ );
require $root . '/includes/Spec/DatasetSchema.php';
require $root . '/includes/Spec/DatasetSqlSchema.php';

$contract = $argv[1] ?? $root . '/contract';
$sqlDir = $root . '/sql';
if ( !is_dir( $contract ) ) {
	mkdir( $contract, 0755, true );
}
if ( !is_dir( $sqlDir ) ) {
	mkdir( $sqlDir, 0755, true );
}

$files = [
	'dataset-manifest.schema.json' => \EmbeddableContent\Spec\DatasetSchema::manifestSchemaJson(),
	'dataset-envelope.schema.json' => \EmbeddableContent\Spec\DatasetSchema::envelopeSchemaJson(),
	'validation-report.schema.json' => \EmbeddableContent\Spec\DatasetSchema::validationReportSchemaJson(),
	'dataset-store-fields.json' => \EmbeddableContent\Spec\DatasetSchema::storeFieldsJson(),
];
foreach ( $files as $name => $content ) {
	file_put_contents( $contract . '/' . $name, $content );
	fwrite( STDOUT, "wrote {$name}\n" );
}

file_put_contents( $sqlDir . '/dataset-schema.sql', \EmbeddableContent\Spec\DatasetSchema::sql() );
fwrite( STDOUT, "wrote sql/dataset-schema.sql\n" );

$manifestPath = $contract . '/dataset-manifest.example.json';
if ( is_file( $manifestPath ) ) {
	$manifest = json_decode( (string)file_get_contents( $manifestPath ), true );
	if ( !is_array( $manifest ) ) {
		fwrite( STDERR, "dataset-manifest.example.json is not valid JSON\n" );
		exit( 1 );
	}
	$envelope = [
		'manifest' => $manifest,
		'validation_report' => \EmbeddableContent\Spec\DatasetSchema::validationReportExample(),
		'manifest_path' => '/figures/manifests/example-2026-t1.json',
	];
	file_put_contents(
		$contract . '/dataset-envelope.example.json',
		\EmbeddableContent\Spec\DatasetSchema::json( $envelope )
	);
	fwrite( STDOUT, "wrote dataset-envelope.example.json\n" );
}
