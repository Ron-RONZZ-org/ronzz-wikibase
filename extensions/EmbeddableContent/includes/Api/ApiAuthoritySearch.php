<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\Fetch\AuthoritySearch;
use MediaWiki\Api\ApiBase;

/**
 * api.php?action=authority-search — read-only external-authority lookup for
 * API/MCP clients (the machine-facing counterpart of the Special:AddSource /
 * Special:AddPerson search step).
 *
 * It exposes the same provider cascade the browser forms use (Wikidata,
 * OpenAlex, Crossref, Open Library, ORCID, dblp, YouTube), the same class
 * scoping, and the same SSRF/rate-limit policy. It never writes.
 *
 * `resolveLocal=1` adds a local-resolution block: publisher/journal/author
 * labels are matched against existing items (exact first, then candidates),
 * mirroring the form's "we think this corresponds to …" autofill.
 *
 * @license GPL-2.0-or-later
 */
class ApiAuthoritySearch extends ApiBase {

	private AuthoritySearch $authoritySearch;

	public function __construct( $mainModule, $moduleName, AuthoritySearch $authoritySearch ) {
		parent::__construct( $mainModule, $moduleName );
		$this->authoritySearch = $authoritySearch;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$result = $this->authoritySearch->search( $params['kind'], $params['mode'], $params );
		$result['records'] = array_slice( $result['records'], 0, (int)$params['limit'] );
		$this->getResult()->addValue( null, 'authoritysearch', $result );
	}

	public function getAllowedParams() {
		return [
			'kind' => [
				self::PARAM_TYPE => [ 'work', 'person', 'entity', 'software' ],
				self::PARAM_REQUIRED => true,
			],
			'mode' => [
				self::PARAM_TYPE => [ 'search', 'lookup', 'harvest' ],
				self::PARAM_DFLT => 'search',
			],
			'query' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => 500,
			],
			'class' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => 50,
			],
			'author' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => 250,
			],
			'authorMode' => [
				self::PARAM_TYPE => [ 'text', 'entity' ],
				self::PARAM_DFLT => 'text',
			],
			'doi' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => 200,
			],
			'isbn' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => 50,
			],
			'orcid' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => 50,
			],
			'viaf' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => 50,
			],
			'isni' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => 50,
			],
			'wikidataId' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => 20,
			],
			'youtubeUrl' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => 500,
			],
			'url' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_MAX_BYTES => 2000,
			],
			'language' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_DFLT => 'en',
				self::PARAM_MAX_BYTES => 20,
			],
			'limit' => [
				self::PARAM_TYPE => 'limit',
				self::PARAM_DFLT => 10,
				self::PARAM_MIN => 1,
				self::PARAM_MAX => 10,
				self::PARAM_MAX2 => 10,
			],
			'resolveLocal' => [
				self::PARAM_TYPE => 'boolean',
				self::PARAM_DFLT => false,
			],
			'fetchPage' => [
				self::PARAM_TYPE => 'boolean',
				self::PARAM_DFLT => true,
			],
		];
	}

	public function isWriteMode() {
		return false;
	}

	public function mustBePosted() {
		return false;
	}

	public function getHelpUrls() {
		return [ 'https://wikibase.ronzz.org/wiki/Help:Contributing/import' ];
	}
}
