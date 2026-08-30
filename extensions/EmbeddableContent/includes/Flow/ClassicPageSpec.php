<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

/**
 * What a classic page needs to be created for an item: the namespace and the
 * transcluded template. Produced by the flow services (SourceFlowService for
 * the Source: pages); consumed by ClassicPageCreator.
 *
 * @license GPL-2.0-or-later
 */
final class ClassicPageSpec {

	public function __construct(
		public readonly int $namespace,
		public readonly string $template,
		public readonly string $pendingMarker = '__EXTERNAL_LINK_PENDING__'
	) {
	}
}
