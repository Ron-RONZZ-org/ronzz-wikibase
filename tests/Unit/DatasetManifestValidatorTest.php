<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Store\DatasetManifestValidator;
use PHPUnit\Framework\TestCase;

/**
 * The wiki-side dataset manifest validator: shape, referential integrity,
 * value rule, size budget and the apply-only PDF provenance check.
 *
 * @license GPL-2.0-or-later
 */
class DatasetManifestValidatorTest extends TestCase {

	private const EXAMPLE = __DIR__ . '/../../extensions/EmbeddableContent/contract/dataset-manifest.example.json';

	public function testGoldenExampleValidates(): void {
		$report = ( new DatasetManifestValidator() )->validate( $this->example(), true );
		$this->assertSame( 'ok', $report['status'], json_encode( $report['checks'] ) );
		$this->assertSame( 1, $report['summary']['tables'] );
		$this->assertSame( 4, $report['summary']['observations'] );
		$this->assertSame( 1, $report['summary']['footnotes'] );
	}

	public function testNormalizeAddsTheImplicitPanelAndTableId(): void {
		$manifest = $this->example();
		unset( $manifest['tables'][0]['table_id'], $manifest['tables'][0]['panels'] );
		$normalized = ( new DatasetManifestValidator() )->normalize( $manifest );
		$this->assertSame( 'example-2026-t1', $normalized['tables'][0]['table_id'] );
		$this->assertSame( 'p1', $normalized['tables'][0]['panels'][0]['panel_key'] );
		$this->assertSame( 'p1', $normalized['tables'][0]['columns'][0]['panel_key'] );
	}

	public function testMissingRequiredFieldIsAnError(): void {
		$manifest = $this->example();
		unset( $manifest['dataset']['title'] );
		$report = ( new DatasetManifestValidator() )->validate( $manifest, false );
		$this->assertSame( 'errors', $report['status'] );
		$this->assertTrue( $this->hasCheck( $report, 'dataset.title' ) );
	}

	public function testUnknownTopLevelFieldIsAnError(): void {
		$manifest = $this->example();
		$manifest['extra'] = true;
		$report = ( new DatasetManifestValidator() )->validate( $manifest, false );
		$this->assertSame( 'errors', $report['status'] );
		$this->assertTrue( $this->hasCheck( $report, 'extra' ) );
	}

	public function testDuplicateColumnKeyIsAnError(): void {
		$manifest = $this->example();
		$manifest['tables'][0]['columns'][1]['column_key'] = 'd';
		$report = ( new DatasetManifestValidator() )->validate( $manifest, false );
		$this->assertSame( 'errors', $report['status'] );
	}

	public function testUnknownFootnoteKeyIsReferentialError(): void {
		$manifest = $this->example();
		$manifest['tables'][0]['observations'][0]['footnote_keys'] = [ 'fn404' ];
		$report = ( new DatasetManifestValidator() )->validate( $manifest, false );
		$this->assertSame( 'errors', $report['status'] );
		$this->assertTrue( $this->hasCheck( $report, 'fn404' ) );
	}

	public function testOrphanFootnoteIsAnError(): void {
		$manifest = $this->example();
		$manifest['tables'][0]['observations'][0]['footnote_keys'] = [];
		$report = ( new DatasetManifestValidator() )->validate( $manifest, false );
		$this->assertSame( 'errors', $report['status'] );
	}

	public function testValueRuleRequiresTheTypedValue(): void {
		$manifest = $this->example();
		unset( $manifest['tables'][0]['observations'][0]['value_float'] );
		$report = ( new DatasetManifestValidator() )->validate( $manifest, false );
		$this->assertSame( 'errors', $report['status'] );
		$this->assertTrue( $this->hasCheck( $report, 'value_float' ) );
	}

	public function testApplyOnlyFieldIsNotRequiredForValidate(): void {
		$manifest = $this->example();
		unset( $manifest['tables'][0]['pdf_sha1'] );
		$validator = new DatasetManifestValidator();
		$this->assertSame( 'ok', $validator->validate( $manifest, false )['status'] );
		$this->assertSame( 'errors', $validator->validate( $manifest, true )['status'] );
	}

	public function testPdfProvenanceMismatch(): void {
		$manifest = $this->example();
		$validator = new DatasetManifestValidator(
			static fn ( string $file, ?int $revision ): ?string => str_repeat( 'f', 40 )
		);
		$report = $validator->validate( $manifest, true );
		$this->assertSame( 'errors', $report['status'] );
		$this->assertTrue( $this->hasCheck( $report, 'pdf_sha1' ) );
	}

	public function testCellCap(): void {
		$manifest = $this->example();
		$table = &$manifest['tables'][0];
		$template = $table['observations'][0];
		$table['observations'] = [];
		for ( $i = 0; $i < 2001; $i++ ) {
			$row = $template;
			$row['row_index'] = $i;
			$row['footnote_keys'] = [];
			$table['observations'][] = $row;
		}
		$report = ( new DatasetManifestValidator() )->validate( $manifest, false );
		$this->assertSame( 'errors', $report['status'] );
		$this->assertTrue( $this->hasCheck( $report, 'size-limit' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function example(): array {
		$data = json_decode( (string)file_get_contents( self::EXAMPLE ), true );
		$this->assertIsArray( $data );
		return $data;
	}

	private function hasCheck( array $report, string $needle ): bool {
		foreach ( $report['checks'] as $check ) {
			if ( str_contains( $check['path'], $needle ) || str_contains( $check['message'], $needle )
				|| str_contains( $check['code'], $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
