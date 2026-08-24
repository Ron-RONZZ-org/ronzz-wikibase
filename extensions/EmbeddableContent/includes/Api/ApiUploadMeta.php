<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\Fetch\UploadMetadataFetcher;
use MediaWiki\Api\ApiBase;

/**
 * api.php?action=uploadmeta&url=… — best-effort metadata for an image the
 * user wants to upload from a URL (the "Validate" step's server-side path,
 * used for NON-Wikimedia hosts; Wikimedia hosts are fetched from the
 * browser directly, see resources/uploadmeta.js).
 *
 * The endpoint performs an SSRF-guarded fetch (SsrfGuard literal checks +
 * rejectLocalUrls transport) and returns ONLY metadata — never the file
 * content. Login-gated: the external-fetch surface, same rule as the Add*
 * search/manual submit handlers. Read-only (no token needed).
 *
 * @license GPL-2.0-or-later
 */
class ApiUploadMeta extends ApiBase {

	public function execute() {
		$this->checkLoginStatus();
		$params = $this->extractRequestParams();

		// Best-effort contract: the fetch never throws; failures come back
		// as warnings in the payload.
		$meta = ( new UploadMetadataFetcher() )->fetch( $params['url'] );

		$this->getResult()->addValue( null, 'uploadmeta', $meta->toArray() );
	}

	/** The SSRF/abuse surface: an anonymous caller must not drive fetches. */
	private function checkLoginStatus(): void {
		if ( $this->getUser()->isRegistered() ) {
			return;
		}
		$this->dieWithError( [ 'uploadmeta-login-required' ], 'loginrequired' );
	}

	public function getAllowedParams() {
		return [
			'url' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
				self::PARAM_MAX_LENGTH => 500,
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
		return 'Return best-effort metadata (name, description, author, license, dimensions, size) for an image URL, for the upload form validate step.';
	}
}
