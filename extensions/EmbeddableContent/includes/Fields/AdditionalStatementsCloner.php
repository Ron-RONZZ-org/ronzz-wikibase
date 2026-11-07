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
	 * @param array<string,string> $propertyOptions "P123 — label" => "P123"
	 * @return array<string,mixed> [ 'additionalStatements' => descriptor ]
	 */
	public static function spec( array $rows = [], array $propertyOptions = [] ): array {
		return [ 'additionalStatements' => [
			'type' => 'cloner',
			'label-message' => 'embeddablecontent-additional-statements',
			'help-message' => 'embeddablecontent-additional-statements-help',
			'create-button-message' => 'embeddablecontent-additional-statements-add',
			'delete-button-message' => 'embeddablecontent-additional-statements-delete',
			'fields' => [
				'property' => [
					'type' => 'combobox',
					'label-message' => 'embeddablecontent-additional-statements-property',
					'options' => $propertyOptions,
					'cssclass' => 'wb-additional-property',
				],
				'value' => [
					'type' => 'combobox',
					'label-message' => 'embeddablecontent-additional-statements-value',
					'options' => [],
					'cssclass' => 'wb-additional-value',
				],
			],
			'default' => $rows,
		] ];
	}

}
