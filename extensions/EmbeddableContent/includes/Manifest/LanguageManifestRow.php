<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Manifest;

/**
 * One row of the language manifest (manifests/languages.csv).
 *
 * Generated from the installed Pygments lexers by tools/generate_language_manifest.py,
 * then human-reviewed. The lexer name is the renderer's canonical contract;
 * the Wikidata Q-id column is filled in during review.
 *
 * Immutable value object.
 *
 * @license GPL-2.0-or-later
 */
class LanguageManifestRow {

	/** @var string canonical Pygments lexer name, e.g. "Python" */
	private $lexer;

	/** @var array<string,string> language code => label */
	private $labels;

	/** @var array<string,string> language code => description */
	private $descriptions;

	/** @var string|null Wikidata Q-id of the language item, e.g. "Q9296" */
	private $wikidataQid;

	public function __construct(
		string $lexer,
		array $labels,
		array $descriptions,
		?string $wikidataQid
	) {
		$this->lexer = $lexer;
		$this->labels = $labels;
		$this->descriptions = $descriptions;
		$this->wikidataQid = $wikidataQid;
	}

	public function getLexer(): string {
		return $this->lexer;
	}

	/** @return array<string,string> */
	public function getLabels(): array {
		return $this->labels;
	}

	/** @return array<string,string> */
	public function getDescriptions(): array {
		return $this->descriptions;
	}

	public function getWikidataQid(): ?string {
		return $this->wikidataQid;
	}
}
