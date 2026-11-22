<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Spec\DatasetSchema;
use PHPUnit\Framework\TestCase;

/**
 * The dataset manifest contract: every committed artifact must be the exact
 * projection of DatasetSchema, so a field added to the definition without
 * re-emitting fails here (and in CI) instead of drifting away from the MCP
 * clients and the PDF extractor that pin the files.
 *
 * Cross-language JSON-Schema validation lives in the consumers
 * (mediawiki-mcp-server with ajv, pdf-figures-mcp with jsonschema); this
 * suite guards the projection and the manifest↔SQL mapping.
 *
 * @license GPL-2.0-or-later
 */
class DatasetSchemaTest extends TestCase {

	private const CONTRACT = __DIR__ . '/../../extensions/EmbeddableContent/contract';
	private const SQL = __DIR__ . '/../../extensions/EmbeddableContent/sql/dataset-schema.sql';

	public function testCommittedManifestSchemaIsCurrent(): void {
		$this->assertCommitted( 'dataset-manifest.schema.json', DatasetSchema::manifestSchemaJson() );
	}

	public function testCommittedEnvelopeSchemaIsCurrent(): void {
		$this->assertCommitted( 'dataset-envelope.schema.json', DatasetSchema::envelopeSchemaJson() );
	}

	public function testCommittedValidationReportSchemaIsCurrent(): void {
		$this->assertCommitted( 'validation-report.schema.json', DatasetSchema::validationReportSchemaJson() );
	}

	public function testCommittedStoreFieldsAreCurrent(): void {
		$this->assertCommitted( 'dataset-store-fields.json', DatasetSchema::storeFieldsJson() );
	}

	public function testCommittedSqlIsCurrent(): void {
		$this->assertFileExists( self::SQL, 'run maintenance/emitDatasetContract.php' );
		$this->assertSame(
			DatasetSchema::sql(),
			file_get_contents( self::SQL ),
			'sql/dataset-schema.sql is stale — run maintenance/emitDatasetContract.php'
		);
	}

	public function testExampleIsVersionedAndCarriesTheCoreObjects(): void {
		$example = $this->readJson( 'dataset-manifest.example.json' );
		$this->assertSame( DatasetSchema::VERSION, $example['manifest_version'] );
		$this->assertArrayHasKey( 'dataset', $example );
		$this->assertArrayHasKey( 'tables', $example );
		$this->assertNotEmpty( $example['tables'] );
		$table = $example['tables'][0];
		foreach ( [ 'columns', 'observations' ] as $key ) {
			$this->assertNotEmpty( $table[$key], "example table has no $key" );
		}
	}

	public function testEnvelopeExampleWrapsTheManifest(): void {
		$envelope = $this->readJson( 'dataset-envelope.example.json' );
		$this->assertArrayHasKey( 'manifest', $envelope );
		$this->assertArrayHasKey( 'validation_report', $envelope );
		$this->assertArrayHasKey( 'manifest_path', $envelope );
		$this->assertSame( DatasetSchema::VERSION, $envelope['manifest']['manifest_version'] );
	}

	public function testEveryStoredFieldMapsToASqlColumn(): void {
		$store = json_decode( DatasetSchema::storeFieldsJson(), true );
		$sql = DatasetSchema::sql();
		foreach ( $store['fields'] as $path => $entry ) {
			[ $table, $column ] = explode( '.', $entry['store'], 2 );
			$this->assertMatchesRegularExpression(
				'/CREATE TABLE IF NOT EXISTS `' . preg_quote( DatasetSchema::TABLE_PREFIX . $table, '/' )
					. '` \((.*?)\) ENGINE=/s',
				$sql,
				"manifest field $path maps to missing table $table"
			);
			preg_match(
				'/CREATE TABLE IF NOT EXISTS `' . preg_quote( DatasetSchema::TABLE_PREFIX . $table, '/' )
					. '` \((.*?)\) ENGINE=/s',
				$sql,
				$match
			);
			$this->assertStringContainsString(
				'`' . $column . '`',
				$match[1],
				"manifest field $path maps to missing column $table.$column"
			);
		}
	}

	public function testGeneratedFieldsAreNotAlsoManifestFields(): void {
		$store = json_decode( DatasetSchema::storeFieldsJson(), true );
		$manifestStores = array_column( $store['fields'], 'store' );
		foreach ( array_keys( $store['generated'] ) as $generated ) {
			$this->assertNotContains(
				$generated,
				$manifestStores,
				"$generated is listed as both supplied and generated"
			);
		}
	}

	public function testRequiredForSeparatesValidateAndApply(): void {
		$store = json_decode( DatasetSchema::storeFieldsJson(), true );
		$this->assertSame(
			[ 'validate', 'apply' ],
			$store['fields']['dataset.dataset_id']['requiredFor']
		);
		$this->assertSame(
			[ 'apply' ],
			$store['fields']['tables[].pdf_sha1']['requiredFor']
		);
		$this->assertSame( [], $store['fields']['dataset.doi']['requiredFor'] );
	}

	/**
	 * @param string $name
	 * @param string $expected
	 */
	private function assertCommitted( string $name, string $expected ): void {
		$path = self::CONTRACT . '/' . $name;
		$this->assertFileExists( $path, 'run maintenance/emitDatasetContract.php' );
		$this->assertSame(
			$expected,
			file_get_contents( $path ),
			"contract/$name is stale — run maintenance/emitDatasetContract.php"
		);
	}

	/**
	 * @param string $name
	 * @return array<string,mixed>
	 */
	private function readJson( string $name ): array {
		$path = self::CONTRACT . '/' . $name;
		$this->assertFileExists( $path );
		$data = json_decode( (string)file_get_contents( $path ), true );
		$this->assertIsArray( $data, "$name is not a JSON object" );
		return $data;
	}
}
