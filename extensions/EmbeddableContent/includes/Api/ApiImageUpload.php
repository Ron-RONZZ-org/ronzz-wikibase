<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Upload\ImageUploadHelper;
use MediaWiki\Api\ApiBase;
use MediaWiki\Language\RawMessage;

/**
 * api.php?action=imageupload — upload (or fetch) one image entry of the
 * multi-image editor on AddPerson/AddCollective/AddSoftware, before the form
 * is submitted.
 *
 * Runs the shared ImageUploadHelper (same fields/page text/image item as the
 * old single portrait/logo section). Returns imageupload.fileTitle / fileUrl.
 *
 * Params: mode=file|url|existing, file=<upload> (mode=file), url=<URL>
 * (mode=url), existing=<File: title> (mode=existing), license=<Q-id>,
 * licenseInfo, author, authorIsEntity, authorEntity, label, description.
 *
 * @license GPL-2.0-or-later
 */
class ApiImageUpload extends ApiBase {

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
		$record = [
			'mediaInclude' => '1',
			'mediaMode' => $mode,
			'mediaLicense' => (string)( $params['license'] ?? '' ),
			'mediaLicenseInfo' => (string)( $params['licenseInfo'] ?? '' ),
			'mediaAuthor' => (string)( $params['author'] ?? '' ),
			'mediaAuthorIsEntity' => !empty( $params['authorIsEntity'] ) ? '1' : '',
			'mediaAuthorEntity' => (string)( $params['authorEntity'] ?? '' ),
			'mediaResize' => '1',
			'mediaAutoExt' => '1',
		];
		if ( $mode === 'url' ) {
			$record['mediaUrl'] = (string)( $params['url'] ?? '' );
		}
		if ( $mode === 'existing' ) {
			$record['mediaExisting'] = (string)( $params['existing'] ?? '' );
		}
		$config = \MediaWiki\MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' );
		$label = trim( (string)( $params['label'] ?? '' ) );
		if ( $label === '' ) {
			$label = 'image';
		}
		$description = trim( (string)( $params['description'] ?? '' ) );
		try {
			$error = ImageUploadHelper::handleUpload(
				'media',
				$record,
				$this,
				$user,
				[
					'error' => 'embeddablecontent-image-error',
					'licenseRequired' => 'embeddablecontent-image-license-required',
					'editSummary' => 'embeddablecontent-image-edit-summary',
				],
				static fn ( array $r ): string => $label,
				$config instanceof EmbeddableContentConfig ? $config : null
			);
		} catch ( \Throwable $e ) {
			$this->dieWithError( new RawMessage( $e->getMessage() ), 'upload_failed' );
		}
		if ( $error !== null ) {
			$this->dieWithError( new RawMessage( $error ), 'upload_failed' );
		}
		$fileTitle = (string)( $record['mediaFileTitle'] ?? '' );
		if ( $fileTitle === '' ) {
			$this->dieWithError( 'apierror-upload-failed', 'upload_failed' );
		}
		$title = \MediaWiki\Title\Title::makeTitle( NS_FILE, $fileTitle );
		$this->getResult()->addValue( null, 'imageupload', [
			'fileTitle' => $fileTitle,
			'fileUrl' => $title !== null ? $title->getFullURL() : '',
			'license' => (string)( $record['mediaLicense'] ?? '' ),
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
				ApiBase::PARAM_TYPE => [ 'file', 'url', 'existing' ],
				ApiBase::PARAM_REQUIRED => true,
			],
			'url' => [ ApiBase::PARAM_TYPE => 'string' ],
			'existing' => [ ApiBase::PARAM_TYPE => 'string' ],
			'license' => [ ApiBase::PARAM_TYPE => 'string' ],
			'licenseInfo' => [ ApiBase::PARAM_TYPE => 'string' ],
			'author' => [ ApiBase::PARAM_TYPE => 'string' ],
			'authorIsEntity' => [ ApiBase::PARAM_TYPE => 'boolean' ],
			'authorEntity' => [ ApiBase::PARAM_TYPE => 'string' ],
			'label' => [ ApiBase::PARAM_TYPE => 'string' ],
			'description' => [ ApiBase::PARAM_TYPE => 'string' ],
		];
	}

	public function getHelpUrls() {
		return [];
	}
}
