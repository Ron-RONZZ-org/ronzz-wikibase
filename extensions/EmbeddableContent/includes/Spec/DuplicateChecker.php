<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\MediaWikiServices;

/**
 * MediaWiki-bound facade for the duplication guard: assembles the record's
 * signal pairs (DuplicateGuard) and runs the DuplicateFinder with the
 * instance's WDQS endpoint (the `sparqlUrl` config key; prefix derivation
 * matches SpecialAddSource's websiteItemByRootHost). Shared by the browser
 * Add* forms and the entity-mode API modules so the guard behaves
 * identically on both surfaces.
 *
 * Exception-safe by contract: any failure (WDQS unreachable, unseeded term
 * store, config shape) yields NO duplicate — the guard never blocks
 * creation. The pure logic (query building, row matching, label scoring)
 * lives in DuplicateFinder and is unit-tested without a MediaWiki runtime.
 *
 * @license GPL-2.0-or-later
 */
final class DuplicateChecker {

	/**
	 * The existing item the given record would duplicate, or null.
	 *
	 * @param array<string,mixed> $record the creation record (browser or API
	 *                                    field vocabulary)
	 * @param string $label the record's primary label ('' skips the label
	 *                      signal)
	 * @param string[] $classItemIds instance-of filter for the label match
	 * @return array{itemId:string,label:string,match:string}|null match is
	 *         'id' (identical external id / URL) or 'label' (fuzzy label)
	 */
	public static function find( EmbeddableContentConfig $config, array $record, string $label, array $classItemIds = [] ): ?array {
		$pairs = DuplicateGuard::pairsFor( $config, $record );
		$endpoint = $config->sparqlUrl();

		if ( $endpoint !== null && $pairs !== [] ) {
			$prefixes = self::entityPrefixes();
			if ( $prefixes !== null ) {
				[ $wd, $wdt ] = $prefixes;
				$finder = new DuplicateFinder(
					static fn ( string $query ): ?array => self::runSparql( $endpoint, $query ),
					null,
					$config->instanceOfPropertyId()
				);
				$dup = $finder->findByValues( $pairs, $wd, $wdt );
				if ( $dup !== null ) {
					return [ 'itemId' => $dup['itemId'], 'label' => $dup['label'], 'match' => 'id' ];
				}
			}
		}

		if ( trim( $label ) !== '' ) {
			$finder = new DuplicateFinder( null, null, $config->instanceOfPropertyId() );
			$dup = $finder->findByLabel( $label, $classItemIds );
			if ( $dup !== null ) {
				return [ 'itemId' => $dup['itemId'], 'label' => $dup['label'], 'match' => 'label' ];
			}
		}

		return null;
	}

	/** @return array{string,string}|null [wd, wdt] entity URI bases, or null */
	private static function entityPrefixes(): ?array {
		$server = $GLOBALS['wgServer'] ?? '';
		if ( !is_string( $server ) || $server === '' ) {
			return null;
		}
		$wd = rtrim( $server, '/' ) . '/entity/';
		return [ $wd, str_replace( '/entity/', '/prop/direct/', $wd ) ];
	}

	/** @return array<int,array<string,mixed>>|null */
	private static function runSparql( string $endpoint, string $query ): ?array {
		try {
			$http = MediaWikiServices::getInstance()->getHttpRequestFactory();
			$request = $http->create(
				$endpoint,
				[ 'method' => 'POST', 'postData' => [ 'query' => $query ], 'timeout' => 10 ],
				__METHOD__
			);
			$request->setHeader( 'Accept', 'application/sparql-results+json' );
			if ( !$request->execute()->isOK() ) {
				return null;
			}
			$decoded = json_decode( $request->getContent(), true );
			if ( !is_array( $decoded ) || !isset( $decoded['results']['bindings'] ) ) {
				return null;
			}
			$rows = $decoded['results']['bindings'];
			return is_array( $rows ) ? $rows : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

}
