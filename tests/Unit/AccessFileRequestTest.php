<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use EmbeddableContent\Spec\AccessFileRequest;
use PHPUnit\Framework\TestCase;

/**
 * The append-only `accessFile` request field of action=addsource: shape
 * normalization only (the File: page and licence item are checked in the
 * MW-bound step).
 *
 * @license GPL-2.0-or-later
 */
class AccessFileRequestTest extends TestCase {

	public function testInternalFileNeedsNoLicenseAndDropsASuppliedOne(): void {
		$entry = AccessFileRequest::normalize( [
			'fileTitle' => 'Foo.pdf',
			'internal' => true,
			'license' => 'Q2799',
			'language' => 'en',
		] );
		$this->assertSame(
			[ 'type' => 'file', 'fileTitle' => 'Foo.pdf', 'public' => '', 'language' => 'en' ],
			$entry
		);
	}

	public function testJsonStringIsAccepted(): void {
		$entry = AccessFileRequest::normalize( json_encode( [
			'fileTitle' => 'File:Foo.pdf',
			'internal' => '1',
		] ) );
		$this->assertIsArray( $entry );
		$this->assertSame( 'File:Foo.pdf', $entry['fileTitle'] );
		$this->assertSame( '', $entry['public'] );
	}

	public function testBooleanSpellingsAreAccepted(): void {
		foreach ( [ true, 1, '1', 'true', 'YES', 'on' ] as $value ) {
			$entry = AccessFileRequest::normalize( [ 'fileTitle' => 'Foo.pdf', 'internal' => $value ] );
			$this->assertIsArray( $entry );
			$this->assertSame( '', $entry['public'], 'internal value: ' . var_export( $value, true ) );
		}
	}

	public function testPublicFileRequiresALicenseItem(): void {
		$this->assertSame(
			'a public accessFile needs a license item id (Q…).',
			AccessFileRequest::normalize( [ 'fileTitle' => 'Foo.pdf' ] )
		);
		$this->assertSame(
			'a public accessFile needs a license item id (Q…).',
			AccessFileRequest::normalize( [ 'fileTitle' => 'Foo.pdf', 'license' => 'CC-BY' ] )
		);
	}

	public function testPublicFileKeepsTheLicense(): void {
		$entry = AccessFileRequest::normalize( [
			'fileTitle' => 'Foo.pdf',
			'license' => 'q320',
		] );
		$this->assertSame(
			[ 'type' => 'file', 'fileTitle' => 'Foo.pdf', 'public' => '1', 'license' => 'Q320' ],
			$entry
		);
	}

	public function testFileTitleIsRequired(): void {
		$this->assertSame(
			'accessFile needs a fileTitle (a File: page title or DB key).',
			AccessFileRequest::normalize( [ 'internal' => true ] )
		);
		$this->assertSame(
			'accessFile needs a fileTitle (a File: page title or DB key).',
			AccessFileRequest::normalize( [ 'fileTitle' => '   ' ] )
		);
	}

	public function testNonObjectInputIsRejected(): void {
		foreach ( [ '', null, 42, 'not json', [ 'a', 'b' ] ] as $value ) {
			$result = AccessFileRequest::normalize( $value );
			$this->assertIsString( $result, 'input: ' . var_export( $value, true ) );
		}
	}
}
