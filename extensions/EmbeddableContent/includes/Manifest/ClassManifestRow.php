<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Manifest;

/**
 * One row of the class manifest (manifests/classes.csv).
 *
 * A class is an ordinary Wikibase Item; it becomes the target of
 * `instance of` statements on content items.
 *
 * Immutable value object.
 *
 * @license GPL-2.0-or-later
 */
class ClassManifestRow {

	/** @var array<string,string> language code => label */
	private $labels;

	/** @var array<string,string> language code => description */
	private $descriptions;

	/** @var string|null canonical vocabulary URI for the alignment statement */
	private $alignUri;

	/** @var string|null Wikidata counterpart URI for the alignment statement */
	private $alignWikidata;

	public function __construct(
		array $labels,
		array $descriptions,
		?string $alignUri,
		?string $alignWikidata
	) {
		$this->labels = $labels;
		$this->descriptions = $descriptions;
		$this->alignUri = $alignUri;
		$this->alignWikidata = $alignWikidata;
	}

	/** @return array<string,string> */
	public function getLabels(): array {
		return $this->labels;
	}

	/** @return array<string,string> */
	public function getDescriptions(): array {
		return $this->descriptions;
	}

	public function getAlignUri(): ?string {
		return $this->alignUri;
	}

	public function getAlignWikidata(): ?string {
		return $this->alignWikidata;
	}
}
