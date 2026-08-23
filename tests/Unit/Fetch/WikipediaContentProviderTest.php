<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\WikipediaContentProvider;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class WikipediaContentProviderTest extends TestCase {

	public function testIntroFromRestSummary(): void {
		$http = ( new FakeHttpClient() )->onJson( 'api/rest_v1/page/summary/', [
			'extract' => 'The quick brown fox is a fictional animal.',
		] );
		$provider = new WikipediaContentProvider( $http );

		$this->assertSame( 'The quick brown fox is a fictional animal.', $provider->intro( 'The quick brown fox' ) );
	}

	public function testIntroNullOnMissingExtract(): void {
		$http = ( new FakeHttpClient() )->onJson( 'api/rest_v1/page/summary/', [ 'title' => 'x' ] );
		$this->assertNull( ( new WikipediaContentProvider( $http ) )->intro( 'x' ) );
	}

	public function testSectionExtractionStopsAtNextHeading(): void {
		$wikitext = "== Plot ==\n\nA boy meets a dog.\n\n=== Sub-plot ===\n\nThey travel.\n\n== Cast ==\n\nSome actors.";
		$http = ( new FakeHttpClient() )->onJson( 'action=query', [
			'query' => [
				'pages' => [
					[ 'revisions' => [ [ 'slots' => [ 'main' => [ 'content' => $wikitext ] ] ] ] ],
				],
			],
		] );
		$provider = new WikipediaContentProvider( $http );

		// The capture stops at the next level-2 heading (== Cast ==) and
		// keeps the level-3 subsection, which renders as part of the section.
		$this->assertSame( 'A boy meets a dog. === Sub-plot === They travel.', $provider->section( 'Film X', [ 'Plot', 'Synopsis' ] ) );
	}

	public function testSectionStripsRefsTemplatesAndComments(): void {
		$wikitext = "== Lyrics ==\n\nLine one.<ref>citation</ref>\n\n{{template|arg}}\n<!-- comment -->\nLine two.";
		$http = ( new FakeHttpClient() )->onJson( 'action=query', [
			'query' => [
				'pages' => [
					[ 'revisions' => [ [ 'slots' => [ 'main' => [ 'content' => $wikitext ] ] ] ] ],
				],
			],
		] );
		$provider = new WikipediaContentProvider( $http );

		$this->assertSame( 'Line one. Line two.', $provider->section( 'Song Y', [ 'Lyrics' ] ) );
	}

	public function testSectionNullWhenHeadingAbsent(): void {
		$http = ( new FakeHttpClient() )->onJson( 'action=query', [
			'query' => [
				'pages' => [
					[ 'revisions' => [ [ 'slots' => [ 'main' => [ 'content' => "== Background ==\n\nNothing." ] ] ] ] ],
				],
			],
		] );
		$this->assertNull( ( new WikipediaContentProvider( $http ) )->section( 'Song Y', [ 'Lyrics' ] ) );
	}

	public function testNetworkFailureYieldsNull(): void {
		$http = ( new FakeHttpClient() )->onError( 'api/rest_v1', 'down' );
		$this->assertNull( ( new WikipediaContentProvider( $http ) )->intro( 'x' ) );
	}
}
