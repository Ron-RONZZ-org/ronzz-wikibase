<?php

declare( strict_types = 1 );

/**
 * Live smoke test for the fetch layer (issue #9).
 *
 * Exercises the real CurlHttpClient + ProviderClient against the public
 * endpoints — the unit suite stays mocked; this is the end-to-end proof.
 *
 * Usage (from the repo root, in the test image):
 *   docker run --rm -v "$PWD":/app -w /app ronzz-wikibase-test \
 *     php tools/fetch-smoke.php
 *
 * Makes a handful of polite requests (one run, then stop).
 *
 * @license GPL-2.0-or-later
 */

require __DIR__ . '/../vendor/autoload.php';

use EmbeddableContent\Fetch\CurlHttpClient;
use EmbeddableContent\Fetch\ProviderClient;

$client = ProviderClient::default( new CurlHttpClient() );

$run = static function ( string $label, callable $fn ): void {
	echo "== {$label} ==\n";
	try {
		$result = $fn();
		foreach ( $result->records as $record ) {
			echo '  - ' . json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		}
		foreach ( $result->warnings as $warning ) {
			echo "  ! warning: {$warning}\n";
		}
		if ( $result->records === [] ) {
			echo "  (no records)\n";
		}
	} catch ( \Throwable $e ) {
		echo '  ! EXCEPTION: ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
	}
	echo "\n";
};

// 1. Person name search — Wikidata hub + OpenAlex + dblp + ORCID cascade.
$run( 'searchPersons("douglas adams")', static fn () => $client->searchPersons( 'douglas adams' ) );

// 2. Identifier-first: DOI → Crossref (deterministic path).
$run( 'byDoi("10.1371/journal.pbio.2001414")', static fn () => $client->byDoi( '10.1371/journal.pbio.2001414' ) );

// 3. Identifier-first: ISBN → Open Library (follows the /isbn → /books redirect).
$run( 'byIsbn("9780684801223")', static fn () => $client->byIsbn( '9780684801223' ) );

// 4. ORCID direct path (cascade: Wikidata SPARQL → OpenAlex → ORCID API).
$run( 'byOrcid("0000-0001-6187-6610")', static fn () => $client->byOrcid( '0000-0001-6187-6610' ) );

// 5. Wikidata hub harvest — nested given/family label resolution.
$run( 'harvestPerson("Q42")', static fn () => $client->harvestPerson( 'Q42' ) );

// 6. Work title search cascade (Wikidata → Open Library → OpenAlex → Crossref).
$run( 'searchWorks("identifiers for the 21st century")', static fn () => $client->searchWorks( 'identifiers for the 21st century' ) );

// 7. Collective entity harvest (AddCollective path) — Q1299 = The Beatles.
$run( 'harvestEntity("Q1299")', static fn () => $client->harvestEntity( 'Q1299' ) );

echo "done\n";
