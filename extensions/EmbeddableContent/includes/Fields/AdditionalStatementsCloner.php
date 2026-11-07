<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fields;

/**
 * The "Additional semantic statements" HTMLForm cloner: one row of
 * {property, value} per extra statement, with the cloner's own [+]/[−]
 * controls. The server validates the property datatype and value in
 * Spec\AdditionalStatements.
 *
 * @license GPL-2.0-or-later
 */
final class AdditionalStatementsCloner {

	/**
	 * @param list<array{property:string,value:string}> $rows prefilled rows
	 * @return array<string,mixed> [ 'additionalStatements' => descriptor ]
	 */
	public static function spec( array $rows = [] ): array {
		return [ 'additionalStatements' => [
			'type' => 'cloner',
			'label-message' => 'embeddablecontent-additional-statements',
			'help-message' => 'embeddablecontent-additional-statements-help',
			'create-button-message' => 'embeddablecontent-additional-statements-add',
			'delete-button-message' => 'embeddablecontent-additional-statements-delete',
			'fields' => [
				'property' => [
					'type' => 'text',
					'label-message' => 'embeddablecontent-additional-statements-property',
					'placeholder' => 'P123',
					'maxlength' => 20,
				],
				'value' => [
					'type' => 'text',
					'label-message' => 'embeddablecontent-additional-statements-value',
				],
			],
			'default' => $rows,
		] ];
	}

}
