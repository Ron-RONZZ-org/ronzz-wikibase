<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\Spec\GroupMembers;
use MediaWiki\Api\ApiBase;
use MediaWiki\Language\RawMessage;

/**
 * api.php?action=groupmembers&qid=Q… — read-only membership lookup for the
 * AddSource author combobox: when a group-of-humans item is picked, the
 * client replaces it with its `has member` Persons so the contributor sees
 * and can remove individual authors before saving.
 *
 * @license GPL-2.0-or-later
 */
class ApiGroupMembers extends ApiBase {

	private GroupMembers $members;

	public function __construct( $mainModule, $moduleName, GroupMembers $members ) {
		parent::__construct( $mainModule, $moduleName );
		$this->members = $members;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$qid = strtoupper( trim( (string)$params['qid'] ) );
		if ( preg_match( '/^Q[1-9]\d*$/', $qid ) !== 1 ) {
			$this->dieWithError( new RawMessage( 'qid must be an item ID (Q<n>).' ), 'invalid_qid' );
		}
		$this->getResult()->addValue( null, 'groupmembers', $this->members->membersOf( $qid ) );
	}

	public function getAllowedParams() {
		return [
			'qid' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
				self::PARAM_MAX_BYTES => 16,
			],
		];
	}

	public function isWriteMode() {
		return false;
	}

	public function mustBePosted() {
		return false;
	}
}
