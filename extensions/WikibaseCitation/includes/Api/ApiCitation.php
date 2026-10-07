<?php

declare( strict_types = 1 );

namespace WikibaseCitation\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
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
		$language = $this->getLanguage()->getCode();

		$entity = trim( (string)( $params['entity'] ?? '' ) );
		$page = trim( (string)( $params['page'] ?? '' ) );
		if ( ( $entity === '' ) === ( $page === '' ) ) {
			// Exactly one of entity / page must be given.
			$this->dieWithError( [ 'wikibasecitation-error-needtarget' ], 'needtarget' );
		}

		if ( $page !== '' ) {
			$this->respondPage( $page, $style, $format, $language );
			return;
		}

		try {
			// style=json returns the raw CSL-JSON structure (nested in the
			// result); every other style is the formatted string.
			$citationText = $style === 'json'
				? $this->engine->renderToCsl( $entity, $language )
				: $this->engine->render( $entity, $style, $format, $language );
		} catch ( InvalidCitationIdException $e ) {
			$this->dieWithError( [ 'wikibasecitation-error-invalidentity' ], 'invalidentity' );
		} catch ( CitationException $e ) {
			$this->dieWithError( [ 'wikibasecitation-error-notfound' ], 'entitynotfound' );
		}

		$this->getMain()->getRequest()->response()->header( 'Access-Control-Allow-Origin: *' );

		$result = $this->getResult();
		$normalized = $this->engine->normalizeItemId( $entity );
		$result->addValue( null, 'entity', $normalized !== null ? $normalized->getSerialization() : $entity );
		$result->addValue( null, 'style', $style );
		$result->addValue( null, 'citation', $citationText );
	}

	/**
	 * The WIKI PAGE citation path (`page=<title>`): the page itself, not its
	 * sitelinked item — title, canonical URL, site name and the last-revision
	 * date (the page's "last edition").
	 */
	private function respondPage( string $page, string $style, string $format, string $language ): void {
		$title = Title::newFromText( $page );
		if ( $title === null || !$title->exists() || !$title->isContentPage() ) {
			$this->dieWithError( [ 'wikibasecitation-error-pagenotfound' ], 'pagenotfound' );
		}

		$timestamp = null;
		$revision = MediaWikiServices::getInstance()->getRevisionLookup()
			->getRevisionByTitle( $title );
		if ( $revision !== null ) {
			$timestamp = $revision->getTimestamp();
		}

		try {
			$citationText = $this->engine->renderPage(
				$title->getPrefixedText(),
				$title->getFullURL(),
				(string)$this->getConfig()->get( 'Sitename' ),
				$timestamp,
				$style,
				$format,
				$language
			);
		} catch ( CitationException $e ) {
			$this->dieWithError( [ 'wikibasecitation-error-notfound' ], 'entitynotfound' );
		}

		$this->getMain()->getRequest()->response()->header( 'Access-Control-Allow-Origin: *' );

		$result = $this->getResult();
		$result->addValue( null, 'page', $title->getPrefixedText() );
		$result->addValue( null, 'style', $style );
		$result->addValue( null, 'citation', $citationText );
	}

	public function getAllowedParams() {
		return [
			'entity' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
			],
			'page' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
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
