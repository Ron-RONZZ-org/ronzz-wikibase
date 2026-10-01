<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

/**
 * Result of a rich-wikitext render: the HTML plus the parser-output module
 * metadata the caller must load for the fragment to render (e.g. the
 * SimpleMathJax module when a math note carries `$…$`).
 *
 * Immutable value object.
 *
 * @license GPL-2.0-or-later
 */
final class RichTextResult {

	/** @var string parsed fragment HTML (MediaWiki-parser sanitized) */
	private $html;

	/** @var string[] ResourceLoader module names */
	private $modules;

	/** @var string[] ResourceLoader module-style names */
	private $moduleStyles;

	/**
	 * @param string $html
	 * @param string[] $modules
	 * @param string[] $moduleStyles
	 */
	public function __construct( string $html, array $modules, array $moduleStyles ) {
		$this->html = $html;
		$this->modules = $modules;
		$this->moduleStyles = $moduleStyles;
	}

	public function getHtml(): string {
		return $this->html;
	}

	/** @return string[] */
	public function getModules(): array {
		return $this->modules;
	}

	/** @return string[] */
	public function getModuleStyles(): array {
		return $this->moduleStyles;
	}
}
