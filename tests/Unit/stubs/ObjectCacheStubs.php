<?php

declare( strict_types = 1 );

/**
 * Pure-PHP stub of Wikimedia\ObjectCache\BagOStuff (MediaWiki core) for the
 * CitationEngine cache tests. In-memory, TTL ignored (tests never sleep);
 * `get` returns false on a miss, matching the real BagOStuff contract the
 * engine relies on.
 *
 * @license GPL-2.0-or-later
 */

namespace Wikimedia\ObjectCache;

class BagOStuff {
	/** @var array<string,mixed> */
	private $data = [];

	public function makeKey( ...$components ): string {
		return implode( ':', array_map( 'strval', $components ) );
	}

	public function get( $key, $flags = 0, &$casToken = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[$key] : false;
	}

	public function set( $key, $value, $expiry = 0, $flags = 0 ): bool {
		$this->data[$key] = $value;
		return true;
	}

	public function delete( $key, $flags = 0 ): bool {
		unset( $this->data[$key] );
		return true;
	}
}
