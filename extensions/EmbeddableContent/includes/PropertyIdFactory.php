<?php

declare( strict_types = 1 );

namespace EmbeddableContent;

use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Entity\PropertyId;

/**
 * Property-id factory across data-model versions: the Wikibase bundle
 * (production, Wikibase 1.46's unreleased 10.0/master data-model) made
 * PropertyId an interface with NumericPropertyId as the concrete class; the
 * repo's unit-test vendor (9.6.1, the latest release) still has PropertyId
 * concrete and no NumericPropertyId. Instantiate whichever exists.
 *
 * One factory for every site that needs a PropertyId: the class-vs-interface
 * split is invisible to the pure-PHP unit suite, so a stray `new PropertyId()`
 * only fails on the deployed runtime.
 *
 * @license GPL-2.0-or-later
 */
final class PropertyIdFactory {

	public static function create( string $serialization ): PropertyId {
		if ( class_exists( NumericPropertyId::class ) ) {
			return new NumericPropertyId( $serialization );
		}
		return new PropertyId( $serialization );
	}

}
