<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * The namespace rules of the smart internal mention: an unpiped link to a
 * classic per-kind page renders the bare entity name, and Source: links are
 * additionally italicised.
 *
 * The namespace ids are instance constants defined by LocalSettings
 * (`dev/config/Extensions.php` on the dev/CI stack); the defined() guards
 * keep the hook safe on a wiki that loads the extension without them.
 *
 * @license GPL-2.0-or-later
 */
final class InternalMention {

	/** Classic per-kind namespaces whose prefix is stripped from the caption. */
	private const CLASSIC_NAMESPACES = [
		'NS_FOSS',
		'NS_PERSON',
		'NS_SOURCE',
		'NS_COLLECTIVE',
		'NS_SOFTWARE',
	];

	/** Whether the namespace is one of the five classic per-kind namespaces. */
	public static function isClassicNamespace( int $namespace ): bool {
		foreach ( self::CLASSIC_NAMESPACES as $constant ) {
			if ( defined( $constant ) && $namespace === constant( $constant ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether the namespace is Source: (the italicised mention namespace). */
	public static function isSourceNamespace( int $namespace ): bool {
		return defined( 'NS_SOURCE' ) && $namespace === NS_SOURCE;
	}

}
