<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Result of a provider cascade: normalized records plus non-fatal warnings
 * (per-provider failures, degraded enrichments) for the UI to surface.
 *
 * @license GPL-2.0-or-later
 */
final class ProviderResult {

	/**
	 * @param object[] $records PersonRecord[] | WorkRecord[] | EntityRecord[]
	 * @param string[] $warnings human-readable, provider-scoped warnings
	 */
	public function __construct(
		public readonly array $records,
		public readonly array $warnings = []
	) {
	}
}
