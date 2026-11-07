<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\Flow\AuthorResolver;
use EmbeddableContent\Spec\AuthorNameList;
use MediaWiki\Api\ApiBase;

/**
 * api.php?action=authorresolve — batch local resolution of a pasted author
 * name string for the AddSource "Search for authors" panel and the MCP
 * author-resolution tool.
 *
 * The module parses the names (AuthorNameList) and resolves each against
 * Person-class items (AuthorResolver). It is READ-ONLY and never accepts a
 * match: every candidate is returned for explicit confirmation, including
 * an exact label match (common names such as "John Smith" are ambiguous).
 *
 * It also returns `collectives` — group-of-humans items whose labels are
 * themselves person-name lists (the legacy "author list stored as a
 * collective" pattern) — so the client can offer to split them into
 * individual Persons.
 *
 * @license GPL-2.0-or-later
 */
class ApiAuthorResolve extends ApiBase {

	private AuthorResolver $resolver;

	public function __construct( $mainModule, $moduleName, AuthorResolver $resolver ) {
		parent::__construct( $mainModule, $moduleName );
		$this->resolver = $resolver;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$input = trim( (string)$params['names'] );
		$parsed = AuthorNameList::parse( $input, (string)$params['nameFormat'] );
		$result = $this->resolver->resolveNames(
			$input,
			$parsed['names'],
			(string)$params['language'],
			(int)$params['limit']
		);
		$result['warnings'] = $parsed['warnings'];
		$result['input'] = $input;
		$this->getResult()->addValue( null, 'authorresolve', $result );
	}

	public function getAllowedParams() {
		return [
			'names' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
				self::PARAM_MAX_BYTES => 2000,
			],
			'nameFormat' => [
				self::PARAM_TYPE => [
					AuthorNameList::FORMAT_AUTO,
					AuthorNameList::FORMAT_COMMA,
					AuthorNameList::FORMAT_SEMICOLON,
					AuthorNameList::FORMAT_SINGLE,
				],
				self::PARAM_DFLT => AuthorNameList::FORMAT_AUTO,
			],
			'language' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_DFLT => 'en',
				self::PARAM_MAX_BYTES => 20,
			],
			'limit' => [
				self::PARAM_TYPE => 'limit',
				self::PARAM_DFLT => 8,
				self::PARAM_MIN => 1,
				self::PARAM_MAX => 25,
				self::PARAM_MAX2 => 25,
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
