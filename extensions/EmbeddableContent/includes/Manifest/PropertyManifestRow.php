<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Manifest;

/**
 * One row of the property manifest (manifests/properties.csv).
 *
 * Immutable value object.
 *
 * @license GPL-2.0-or-later
 */
class PropertyManifestRow {

	/** @var array<string,string> language code => label */
	private $labels;

	/** @var array<string,string> language code => description */
	private $descriptions;

	/** @var string Wikibase datatype id, e.g. "wikibase-item" */
	private $datatype;

	/** @var string|null canonical vocabulary URI for the alignment statement */
	private $alignUri;

	/** @var string|null Wikidata counterpart URI for the alignment statement */
	private $alignWikidata;

	/** @var string|null formatter URL template (external-id properties only) */
	private $formatterUrl;

	public function __construct(
		array $labels,
		array $descriptions,
		string $datatype,
		?string $alignUri,
		?string $alignWikidata,
		?string $formatterUrl = null
	) {
		$this->labels = $labels;
		$this->descriptions = $descriptions;
		$this->datatype = $datatype;
		$this->alignUri = $alignUri;
		$this->alignWikidata = $alignWikidata;
		$this->formatterUrl = $formatterUrl;
	}

	/** @return array<string,string> */
	public function getLabels(): array {
		return $this->labels;
	}

	/** @return array<string,string> */
	public function getDescriptions(): array {
		return $this->descriptions;
	}

	public function getDatatype(): string {
		return $this->datatype;
	}

	public function getAlignUri(): ?string {
		return $this->alignUri;
	}

	public function getAlignWikidata(): ?string {
		return $this->alignWikidata;
	}

	public function getFormatterUrl(): ?string {
		return $this->formatterUrl;
	}
}
