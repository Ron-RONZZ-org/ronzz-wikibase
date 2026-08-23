<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Lightweight metadata extracted from a fetched web page (website/webpage
 * URL-first flow). Plain strings — an empty value simply means the field
 * was not found (or the fetch failed).
 *
 * @license GPL-2.0-or-later
 */
final class PageMetadata {

	public function __construct(
		public readonly string $title = '',
		public readonly string $description = '',
		public readonly string $intro = '',
		public readonly string $keywords = ''
	) {
	}

	/** True when nothing at all was extracted. */
	public function isEmpty(): bool {
		return $this->title === '' && $this->description === '' && $this->intro === '' && $this->keywords === '';
	}
}
