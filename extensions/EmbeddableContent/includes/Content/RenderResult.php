<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

/**
 * Result of a render call: the fragment HTML plus metadata used for caching,
 * language negotiation and JSON output.
 *
 * Immutable value object.
 *
 * @license GPL-2.0-or-later
 */
class RenderResult {

	/** @var string kind: quotation | code | math */
	private $kind;

	/** @var string */
	private $title;

	/** @var string fragment HTML (sanitized) */
	private $html;

	/** @var string negotiated language code */
	private $lang;

	/** @var array<string,string> all available payload languages => text (quotation) */
	private $languages;

	/** @var string cache key */
	private $cacheKey;

	/** @var int|null revision timestamp (unix) for Last-Modified */
	private $lastModified;

	/** @var string[] ResourceLoader modules the fragment needs */
	private $modules;

	/** @var string[] ResourceLoader module styles the fragment needs */
	private $moduleStyles;

	public function __construct(
		string $kind,
		string $title,
		string $html,
		string $lang,
		array $languages,
		string $cacheKey,
		?int $lastModified,
		array $modules = [],
		array $moduleStyles = []
	) {
		$this->kind = $kind;
		$this->title = $title;
		$this->html = $html;
		$this->lang = $lang;
		$this->languages = $languages;
		$this->cacheKey = $cacheKey;
		$this->lastModified = $lastModified;
		$this->modules = $modules;
		$this->moduleStyles = $moduleStyles;
	}

	public function getKind(): string {
		return $this->kind;
	}

	public function getTitle(): string {
		return $this->title;
	}

	public function getHtml(): string {
		return $this->html;
	}

	public function getLang(): string {
		return $this->lang;
	}

	/** @return array<string,string> */
	public function getLanguages(): array {
		return $this->languages;
	}

	public function getCacheKey(): string {
		return $this->cacheKey;
	}

	public function getEtag(): string {
		return '"' . md5( $this->cacheKey ) . '"';
	}

	public function getLastModified(): ?int {
		return $this->lastModified;
	}

	/** @return string[] ResourceLoader modules the fragment needs */
	public function getModules(): array {
		return $this->modules;
	}

	/** @return string[] ResourceLoader module styles the fragment needs */
	public function getModuleStyles(): array {
		return $this->moduleStyles;
	}
}
