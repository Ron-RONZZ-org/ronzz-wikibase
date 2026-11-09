<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\Upload\AccessUploadHelper;
use MediaWiki\Api\ApiBase;
use MediaWiki\Language\RawMessage;
use MediaWiki\Request\WebRequestUpload;

/**
 * api.php?action=accessupload — upload (or fetch) the file of one multi-access
 * entry from the AddSource/UpdateSource editor, before the form is submitted.
 *
 * Params: mode=file|url, file=<upload> (mode=file), url=<direct URL>
 * (mode=url), label=<item label for the destination name>, internal=1 for a
 * login-only entry.
 *
 * Returns: accessupload.fileTitle / fileUrl.
 *
 * @license GPL-2.0-or-later
 */
class ApiAccessUpload extends ApiBase {

	public function execute() {
		$user = $this->getUser();
		if ( !$user->isRegistered() ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}
		if ( !$user->isAllowed( 'upload' ) ) {
			$this->dieWithError( 'apierror-permissiondenied-generic', 'permissiondenied' );
		}
		$params = $this->extractRequestParams();
		$mode = $params['mode'];
		$label = trim( (string)$params['label'] );
		if ( $label === '' ) {
			$label = 'access-file';
		}
		$description = trim( (string)$params['description'] );
		$internal = !empty( $params['internal'] );
		try {
			if ( $mode === 'file' ) {
				$upload = $this->getRequest()->getUpload( 'file' );
				if ( !$upload instanceof WebRequestUpload ) {
					$this->dieWithError( 'apierror-missingparam-file', 'missing_file' );
				}
				$title = AccessUploadHelper::uploadFile(
					$this,
					$user,
					$upload,
					$label,
					$description,
					$internal
				);
			} else {
				$url = trim( (string)$params['url'] );
				if ( $url === '' ) {
					$this->dieWithError( 'apierror-missingparam-url', 'missing_url' );
				}
				$title = AccessUploadHelper::uploadUrl(
					$this,
					$user,
					$url,
					$label,
					$description,
					$internal
				);
			}
		} catch ( \Throwable $e ) {
			$this->dieWithError( new RawMessage( $e->getMessage() ), 'upload_failed' );
		}
		if ( $title === null ) {
			$this->dieWithError( 'apierror-upload-failed', 'upload_failed' );
		}
		$this->getResult()->addValue( null, 'accessupload', [
			'fileTitle' => $title->getDBkey(),
			'fileUrl' => $title->getFullURL(),
		] );
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function getAllowedParams() {
		return [
			'mode' => [
				ApiBase::PARAM_TYPE => [ 'file', 'url' ],
				ApiBase::PARAM_REQUIRED => true,
			],
			'url' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'label' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'description' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'internal' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
		];
	}

	public function getHelpUrls() {
		return [];
	}
}
