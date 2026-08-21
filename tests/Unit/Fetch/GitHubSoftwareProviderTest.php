<?php

declare( strict_types = 1 );

namespace Tests\Unit\Fetch;

use EmbeddableContent\Fetch\GitHubSoftwareProvider;
use EmbeddableContent\Fetch\SoftwareRecord;
use PHPUnit\Framework\TestCase;

/**
 * @license GPL-2.0-or-later
 */
final class GitHubSoftwareProviderTest extends TestCase {

	public function testSearchMapsRepos(): void {
		$http = ( new FakeHttpClient() )->onJson( '/search/repositories?q=flameshot', [
			'items' => [
				[
					'full_name' => 'flameshot-org/flameshot',
					'name' => 'flameshot',
					'description' => 'Powerful yet simple to use screenshot software',
					'html_url' => 'https://github.com/flameshot-org/flameshot',
					'homepage' => 'https://flameshot.org',
					'language' => 'C++',
					'license' => [ 'spdx_id' => 'GPL-3.0' ],
				],
			],
		] );
		$provider = new GitHubSoftwareProvider( $http );

		$records = $provider->searchByName( 'flameshot' );

		$this->assertCount( 1, $records );
		$this->assertSame( 'flameshot-org/flameshot', $records[0]->label );
		$this->assertSame( 'flameshot-org/flameshot', $records[0]->githubFullName );
		$this->assertSame( 'https://flameshot.org', $records[0]->website );
		$this->assertSame( 'https://github.com/flameshot-org/flameshot', $records[0]->sourceRepository );
		$this->assertSame( 'GPL-3.0', $records[0]->license );
		$this->assertSame( 'C++', $records[0]->programmingLanguage );
		$this->assertSame( 'github', $records[0]->provider );
	}

	public function testByFullName(): void {
		$http = ( new FakeHttpClient() )->onJson( '/repos/flameshot-org/flameshot', [
			'full_name' => 'flameshot-org/flameshot',
			'name' => 'flameshot',
			'description' => 'Powerful yet simple to use screenshot software',
			'html_url' => 'https://github.com/flameshot-org/flameshot',
			'homepage' => 'https://flameshot.org',
			'language' => 'C++',
			'license' => null,
		] );
		$provider = new GitHubSoftwareProvider( $http );

		$record = $provider->byFullName( 'flameshot-org/flameshot' );

		$this->assertInstanceOf( SoftwareRecord::class, $record );
		$this->assertSame( 'flameshot-org/flameshot', $record->githubFullName );
		$this->assertNull( $record->license ); // license: null in the payload → no license
	}

	public function testByFullNameReturnsNullForUnknownRepo(): void {
		$http = ( new FakeHttpClient() )->onJson( '/repos/nope/nope', [] );
		$provider = new GitHubSoftwareProvider( $http );

		$this->assertNull( $provider->byFullName( 'nope/nope' ) );
	}
}
