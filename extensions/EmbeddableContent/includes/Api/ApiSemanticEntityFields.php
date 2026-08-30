<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\SemanticEntityFieldMap;
use MediaWiki\Api\ApiBase;

/**
 * api.php?action=addsemanticentity-fields — read-only discovery counterpart
 * of action=addsemanticentity: the kinds, their accepted fields and
 * required-on-create rules, plus the resolved property ids. What the MCP
 * embeddable-describe-entity-type tool formats for the semantic-entity
 * section.
 *
 * @license GPL-2.0-or-later
 */
class ApiSemanticEntityFields extends ApiBase {

	/** @var EmbeddableContentConfig */
	private $config;

	public function __construct( $mainModule, $moduleName, EmbeddableContentConfig $config ) {
		parent::__construct( $mainModule, $moduleName );
		$this->config = $config;
	}

	public function execute() {
		$kinds = [];
		foreach ( SemanticEntityFieldMap::KINDS as $kind ) {
			$kinds[] = [
				'kind' => $kind,
				'fields' => SemanticEntityFieldMap::fieldsForKind( $kind ),
				'requiredOnCreate' => SemanticEntityFieldMap::requiredOnCreate( $kind ),
			];
		}

		$this->getResult()->addValue( null, 'semanticfields', [
			'kinds' => $kinds,
			'propertyIds' => [
				'instanceOf' => $this->config->instanceOfPropertyId(),
				'programmingLanguage' => $this->config->programmingLanguagePropertyId(),
				'personProperties' => $this->config->personPropertyIds(),
				'fossProperties' => $this->config->fossPropertyIds(),
				'collectiveProperties' => $this->config->collectivePropertyIds(),
				'fictionalCharacter' => $this->config->fictionalCharacterPropertyIds(),
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
}
