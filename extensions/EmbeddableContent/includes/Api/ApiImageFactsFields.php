<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Api\ApiBase;

/**
 * api.php?action=imagefacts-fields — read-only discovery counterpart of
 * action=imagefacts: the image class/property ids and the wiki's preseeded
 * license items (label => Q-id).
 *
 * The license map is what the review-form combobox offers; exposing it lets
 * an MCP client resolve a license label without a fuzzy entity search (and
 * without hardcoding instance-specific Q-ids in a prompt or tool).
 *
 * @license GPL-2.0-or-later
 */
class ApiImageFactsFields extends ApiBase {

	/** @var EmbeddableContentConfig */
	private $config;

	public function __construct( $mainModule, $moduleName, EmbeddableContentConfig $config ) {
		parent::__construct( $mainModule, $moduleName );
		$this->config = $config;
	}

	public function execute() {
		$provenance = $this->config->provenancePropertyIds();
		$this->getResult()->addValue( null, 'imagefactsfields', [
			'instanceOf' => $this->config->instanceOfPropertyId(),
			'imageClasses' => $this->config->imageClasses(),
			'imageProperties' => $this->config->imagePropertyIds(),
			'provenance' => [
				'sourceUrl' => $provenance['sourceUrl'] ?? null,
			],
			// label => item id (the preseed license vocabulary); empty when
			// the instance was seeded without preseed licenses.
			'licenses' => $this->config->licenseItems(),
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

	public function getModuleDescription() {
		return 'Returns the image-facts vocabulary (image class/property ids + the preseeded license label => Q-id map) for action=imagefacts clients.';
	}
}
