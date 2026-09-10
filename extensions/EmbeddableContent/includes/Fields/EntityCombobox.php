<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fields;

use EmbeddableContent\EmbeddableContentConfig;

/**
 * The single place that builds entity-combobox field specs for the Add*
 * pages and Special:Upload.
 *
 * Before this class the same `type => combobox` / `cssclass =>
 * wb-entity-combobox` / `options => []` block was hand-written at every
 * call site (and the license combobox had four separate implementations),
 * which made the class-scope wiring easy to forget. Every entity combobox
 * now goes through here, so:
 *  - the `data-wb-classes` scope (see OOUIComboboxField + entitysuggest.js)
 *    is set consistently, and
 *  - the multi-value marker (`wb-entity-combobox-multi`) is set from one
 *    flag instead of by string concatenation.
 *
 * @license GPL-2.0-or-later
 */
final class EntityCombobox {

	/**
	 * A class-scoped entity combobox field spec (without the field name).
	 *
	 * @param string $msgKey label-message key
	 * @param string[] $classIds item ids the search is restricted to; [] = all items
	 * @param bool $multi comma/semicolon-separated multi-value field
	 * @param array<string,mixed> $extra merged over the defaults (options, default,
	 *  help, hide-if, …)
	 * @return array<string,mixed>
	 */
	public static function spec( string $msgKey, array $classIds = [], bool $multi = false, array $extra = [] ): array {
		return array_merge( [
			'type' => 'combobox',
			'options' => [],
			'label-message' => $msgKey,
			'cssclass' => 'wb-entity-combobox' . ( $multi ? ' wb-entity-combobox-multi' : '' ),
			'class' => OOUIComboboxField::class,
			'wbClasses' => array_values( array_filter( $classIds ) ),
		], $extra );
	}

	/**
	 * A named field spec: [ $name => spec ].
	 *
	 * @param string[] $classIds
	 * @param array<string,mixed> $extra
	 * @return array<string,array<string,mixed>>
	 */
	public static function field( string $name, string $msgKey, array $classIds = [], bool $multi = false, array $extra = [] ): array {
		return [ $name => self::spec( $msgKey, $classIds, $multi, $extra ) ];
	}

	/**
	 * The shared license combobox: scoped to the software-license class and
	 * pre-populated with the config's known license items. Used by the
	 * portrait/logo uploads, the AddSource access field, Special:AddSoftware's
	 * own license fact and Special:Upload.
	 *
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	public static function licenseSpec( string $msgKey, string $helpMsg, EmbeddableContentConfig $config, array $extra = [] ): array {
		$class = $config->softwareLicenseClass();
		return self::spec( $msgKey, $class === null ? [] : [ $class ], false, array_merge( [
			'options' => $config->licenseItems(),
			'help-message' => $helpMsg,
		], $extra ) );
	}
}
