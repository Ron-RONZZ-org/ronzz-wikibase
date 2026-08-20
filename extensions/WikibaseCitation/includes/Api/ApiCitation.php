<?php

declare( strict_types = 1 );

namespace WikibaseCitation\Api;

use MediaWiki\Api\ApiBase;
use WikibaseCitation\CitationEngine;
use WikibaseCitation\CitationException;
use WikibaseCitation\CitationFormatter;
use WikibaseCitation\InvalidCitationIdException;

/**
 * api.php?action=citation&entity=Q1&style=json|apa|vancouver|bibtex|ris&format=html|text
 * (issue #6 §7). Thin surface: parameter validation + result shape; the
 * rendering itself is the shared CitationEngine (issue #24) — entity id →
 * item → CSL-JSON → formatted string, revId-keyed cache, sanitized html.
 *
 * @license GPL-2.0-or-later
 */
class ApiCitation extends ApiBase {

	/** @var CitationEngine */
	private $engine;

	public function __construct( $mainModule, $moduleName, CitationEngine $engine ) {
		parent::__construct( $mainModule, $moduleName );
		$this->engine = $engine;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$style = $params['style'];
		$format = $params['output'];

		try {
			// style=json returns the raw CSL-JSON structure (nested in the
			// result); every other style is the formatted string.
			$citationText = $style === 'json'
				? $this->engine->renderToCsl( $params['entity'] )
				: $this->engine->render( $params['entity'], $style, $format );
		} catch ( InvalidCitationIdException $e ) {
			$this->dieWithError( [ 'wikibasecitation-error-invalidentity' ], 'invalidentity' );
		} catch ( CitationException $e ) {
			$this->dieWithError( [ 'wikibasecitation-error-notfound' ], 'entitynotfound' );
		}

		$this->getMain()->getRequest()->response()->header( 'Access-Control-Allow-Origin: *' );

		$result = $this->getResult();
		$normalized = $this->engine->normalizeItemId( $params['entity'] );
		$result->addValue( null, 'entity', $normalized !== null ? $normalized->getSerialization() : $params['entity'] );
		$result->addValue( null, 'style', $style );
		$result->addValue( null, 'citation', $citationText );
	}

	public function getAllowedParams() {
		return [
			'entity' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
			],
			'style' => [
				self::PARAM_TYPE => CitationFormatter::STYLES,
				self::PARAM_DFLT => 'json',
			],
			'output' => [
				self::PARAM_TYPE => [ 'html', 'text' ],
				self::PARAM_DFLT => 'text',
			],
		];
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
		return 'Cite a Wikibase content item in json, APA, Vancouver, BibTeX or RIS format.';
	}
}
