<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Flow\FieldContract;
use EmbeddableContent\Flow\SemanticEntityFieldMap;
use PHPUnit\Framework\TestCase;

/**
 * The canonical field-contract artifact: the committed JSON must be the
 * EXACT projection of the field maps, so a field added to a map without
 * re-emitting fails here (CI) instead of silently drifting away from the
 * clients that pin the contract.
 *
 * @license GPL-2.0-or-later
 */
class FieldContractTest extends TestCase {

	private const CONTRACT_PATH = __DIR__ . '/../../extensions/EmbeddableContent/contract/field-contract.json';

	public function testCommittedContractIsTheFieldMapProjection(): void {
		$this->assertFileExists(
			self::CONTRACT_PATH,
			'the canonical contract file is missing — run maintenance/emitFieldContract.php'
		);
		$this->assertSame(
			FieldContract::toJson(),
			file_get_contents( self::CONTRACT_PATH ),
			'contract/field-contract.json is stale — run maintenance/emitFieldContract.php and commit it'
		);
	}

	public function testContractIsVersionedAndCarriesAllFlows(): void {
		$contract = json_decode( FieldContract::toJson(), true );
		$this->assertIsArray( $contract );
		$this->assertSame( FieldContract::VERSION, $contract['version'] );
		$this->assertSame(
			[ 'special-content', 'citation-source', 'semantic-entity' ],
			array_keys( $contract['flows'] )
		);
		// Every entry carries a field list and a required-on-create list.
		foreach ( $contract['flows'] as $flow ) {
			$group = $flow['kinds'] ?? $flow['classes'] ?? null;
			$this->assertIsArray( $group );
			$this->assertNotEmpty( $group );
			foreach ( $group as $entry ) {
				$this->assertArrayHasKey( 'fields', $entry );
				$this->assertArrayHasKey( 'requiredOnCreate', $entry );
				$this->assertNotEmpty( $entry['fields'] );
			}
		}
	}

	public function testSemanticEntityApiParamFieldsAreTheMapMinusTypedParams(): void {
		$apiFields = SemanticEntityFieldMap::apiParamFields();
		$this->assertSame(
			array_values( array_diff( SemanticEntityFieldMap::ALL_FIELDS, SemanticEntityFieldMap::API_TYPED_FIELDS ) ),
			$apiFields
		);
		// Regression: alias (the fictional-character aliases) must be an
		// accepted API param — it was advertised by the discovery endpoint but
		// silently dropped by action=addsemanticentity.
		$this->assertContains( 'alias', $apiFields );
		// pageKind is a typed enum param, not a generic string field.
		$this->assertNotContains( 'pageKind', $apiFields );
		// `statements` is not a supported flow field — a ghost in the old map.
		$this->assertNotContains( 'statements', SemanticEntityFieldMap::ALL_FIELDS );
	}
}
