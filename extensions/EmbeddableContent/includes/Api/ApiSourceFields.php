<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\SourceFieldMap;
use MediaWiki\Api\ApiBase;

/**
 * api.php?action=addsource-fields — read-only discovery counterpart of
 * action=addsource: the classes, their accepted fields, required-on-create
 * rules, and the resolved property ids. What the MCP embeddable-describe-
 * entity-type tool formats for the citation-source section, so the field
 * contract (SourceFieldMap) has a single publisher on the wiki.
 *
 * @license GPL-2.0-or-later
 */
class ApiSourceFields extends ApiBase {

	/** @var EmbeddableContentConfig */
	private $config;

	public function __construct( $mainModule, $moduleName, EmbeddableContentConfig $config ) {
		parent::__construct( $mainModule, $moduleName );
		$this->config = $config;
	}

	public function execute() {
		$classes = [];
		foreach ( SourceFieldMap::CLASS_KEYS as $classKey ) {
			$classes[] = [
				'classKey' => $classKey,
				'label' => $this->classLabel( $classKey ),
				'parentClass' => SourceFieldMap::PARENT_CLASS[$classKey] ?? null,
				'fields' => SourceFieldMap::fieldsForClass( $classKey ),
				'requiredOnCreate' => SourceFieldMap::requiredOnCreate( $classKey ),
			];
		}

		$this->getResult()->addValue( null, 'sourcefields', [
			'classes' => $classes,
			'propertyIds' => [
				'instanceOf' => $this->config->instanceOfPropertyId(),
				'provenance' => $this->config->provenancePropertyIds(),
				'citationMetadata' => $this->config->citationMetadataPropertyIds(),
				'sourceProperties' => $this->config->sourcePropertyIds(),
				'externalIds' => $this->config->externalIdPropertyIds(),
			],
		] );
	}

	public function getAllowedParams() {
		return [];
	}

	public function isWriteMode() {
		return false;
	}

	public function mustBePosted() {
		return false;
	}

	public function needsToken() {
		return false;
	}

	private function classLabel( string $classKey ): string {
		return wfMessage( 'embeddablecontent-source-class-' . SourceFieldMap::formKey( $classKey ) )
			->inLanguage( 'en' )->text();
	}
}
