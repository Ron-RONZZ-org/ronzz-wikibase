<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * URL query-param prefill for the Add* / Update* HTMLForms (deep links): a
 * field whose NAME appears in the request query string takes that value as
 * its default, so a link such as
 *
 *   Special:AddSource/law/manual?parent=Q2048&referenceCode=Article%209
 *
 * opens the form already filled. This makes the browser forms reachable as
 * a "web API": any tool that can build a URL can hand the contributor a
 * prefilled form.
 *
 * POST data is unaffected — HTMLForm's action URL carries no query string on
 * POST (the submitted fields arrive wp-prefixed), so a normal submit never
 * re-applies the URL. The prefilled values are re-validated on submit
 * (beforeCreate / the flow service), so this is display-only convenience and
 * adds no write surface.
 *
 * Pure (no MediaWiki dependency) so it is unit-testable.
 *
 * @license GPL-2.0-or-later
 */
final class RequestPrefill {

	/**
	 * Field types never prefilled from the URL: markers the server owns
	 * (hidden), the form's own controls (submit/button) and read-only chrome
	 * (info/html). The translations cloner expects an array, which a scalar
	 * query value would break.
	 */
	private const SKIP_TYPES = [ 'hidden', 'submit', 'button', 'info', 'html', 'cloner' ];

	/**
	 * @param array<string,mixed> $fields HTMLForm field specs (name => spec)
	 * @param array<string,mixed> $query request query values
	 * @return array<string,mixed> the specs with matching defaults applied
	 */
	public static function apply( array $fields, array $query ): array {
		foreach ( $fields as $name => $spec ) {
			if ( !is_array( $spec ) || !array_key_exists( $name, $query ) ) {
				continue;
			}
			if ( in_array( $spec['type'] ?? 'text', self::SKIP_TYPES, true ) ) {
				continue;
			}
			$value = $query[$name];
			if ( !is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string)$value );
			if ( $value === '' ) {
				continue;
			}
			$fields[$name]['default'] = $value;
		}
		return $fields;
	}
}
