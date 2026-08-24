<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Best-effort metadata for an image the user wants to upload from a URL —
 * the payload of the "Validate" step (Special:Upload URL mode + the Add*
 * portrait/logo URL fields).
 *
 * Every field is nullable: the fetch is best-effort (the user can always
 * fill the form by hand). `warnings` carries human-readable problems the
 * caller should surface ("not an image", "too large to probe", …).
 *
 * @license GPL-2.0-or-later
 */
final class ImageMetadata {

	/** @param string[] $warnings */
	public function __construct(
		public readonly ?string $name,
		public readonly ?string $description,
		public readonly ?string $author,
		public readonly ?string $licenseLabel,
		public readonly ?string $credit,
		public readonly ?int $width,
		public readonly ?int $height,
		public readonly ?int $fileSize,
		public readonly ?string $mime,
		public readonly ?string $thumbUrl,
		public readonly string $sourceUrl,
		public readonly array $warnings
	) {
	}

	/** Metadata for a failed fetch (unsafe URL, network error, …). */
	public static function failure( string $url, string $warning ): self {
		return new self(
			name: null,
			description: null,
			author: null,
			licenseLabel: null,
			credit: null,
			width: null,
			height: null,
			fileSize: null,
			mime: null,
			thumbUrl: null,
			sourceUrl: $url,
			warnings: [ $warning ]
		);
	}

	/** @return array<string,mixed> the wire shape for api.php?action=uploadmeta */
	public function toArray(): array {
		return [
			'name' => $this->name,
			'description' => $this->description,
			'author' => $this->author,
			'license' => $this->licenseLabel,
			'credit' => $this->credit,
			'width' => $this->width,
			'height' => $this->height,
			'fileSize' => $this->fileSize,
			'mime' => $this->mime,
			'thumbUrl' => $this->thumbUrl,
			'sourceUrl' => $this->sourceUrl,
			'warnings' => $this->warnings,
		];
	}
}
