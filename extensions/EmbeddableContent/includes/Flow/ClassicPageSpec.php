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
		public readonly string $pendingMarker = '__EXTERNAL_LINK_PENDING__',
		// The AddSource form class key (book, scholarlyArticle, law, …) so
		// the shared SourcePageSkeleton can render the per-class sections.
		// Empty for the generic (Person/Collective/Main) page specs.
		public readonly string $classKey = ''
	) {
	}
}
