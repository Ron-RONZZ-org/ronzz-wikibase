<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\SpecialContentFieldMap;
use MediaWiki\Api\ApiBase;

/**
 * api.php?action=addspecialcontent-fields — read-only discovery counterpart
 * of action=addspecialcontent: the kinds, their accepted fields and
 * required-on-create rules, plus the resolved property ids. What the MCP
 * embeddable-describe-entity-type tool formats for the special-content
 * section.
 *
 * @license GPL-2.0-or-later
 */
class ApiSpecialContentFields extends ApiBase {

	/** @var EmbeddableContentConfig */
	private $config;

	public function __construct( $mainModule, $moduleName, EmbeddableContentConfig $config ) {
		parent::__construct( $mainModule, $moduleName );
		$this->config = $config;
	}

	public function execute() {
		$kinds = [];
		foreach ( SpecialContentFieldMap::KINDS as $kind ) {
			$kinds[] = [
				'kind' => $kind,
				'classItemId' => $this->config->classIds()[$kind] ?? null,
				'payloadPropertyId' => $this->config->payloadPropertyIds()[$kind] ?? null,
				'fields' => SpecialContentFieldMap::fieldsForKind( $kind ),
				'requiredOnCreate' => SpecialContentFieldMap::requiredOnCreate( $kind ),
			];
		}

		$this->getResult()->addValue( null, 'contentfields', [
			'kinds' => $kinds,
			'propertyIds' => [
				'instanceOf' => $this->config->instanceOfPropertyId(),
				'payloadProperties' => $this->config->payloadPropertyIds(),
				'programmingLanguage' => $this->config->programmingLanguagePropertyId(),
				'describes' => $this->config->describesPropertyId(),
				'implementationOf' => $this->config->implementationOfPropertyId(),
				'provenance' => $this->config->provenancePropertyIds(),
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
}
