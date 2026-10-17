<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Upload\ImageFactsRequest;
use EmbeddableContent\Upload\ImageItemCreator;
use MediaWiki\Api\ApiBase;
use MediaWiki\Message\RawMessage;
use MediaWiki\Title\Title;

/**
 * api.php?action=imagefacts — attach the semantic image facts to an
 * already-uploaded File: page.
 *
 * The Special:Upload form creates/reuses the sitelinked image item through
 * UploadComplete, but API uploads (the MediaWiki MCP server, scripts) bypass
 * that hook. This module exposes the same ImageItemCreator path to API
 * clients, so an upload can carry license/author/source facts regardless of
 * how the bytes arrived.
 *
 * The caller supplies the File: title plus the optional fact fields; the
 * page text (human-readable attribution) stays the caller's responsibility,
 * exactly as with upload-file's `text` parameter.
 *
 * @license GPL-2.0-or-later
 */
class ApiImageFacts extends ApiBase {

	/** @var EmbeddableContentConfig */
	private $config;

	public function __construct( $mainModule, $moduleName, EmbeddableContentConfig $config ) {
		parent::__construct( $mainModule, $moduleName );
		$this->config = $config;
	}

	public function execute() {
		$this->checkUserRightsAny( 'edit' );
		$params = $this->extractRequestParams();

		$fileName = ImageFactsRequest::normalizeFileName( (string)$params['filename'] );
		if ( $fileName === null ) {
			$this->dieWithError(
				new RawMessage( 'filename must name a File: page.' ),
				'invalid_filename'
			);
		}
		$title = Title::makeTitleSafe( NS_FILE, $fileName );
		if ( $title === null ) {
			$this->dieWithError(
				new RawMessage( "filename \"{$fileName}\" is not a valid file title." ),
				'invalidtitle'
			);
		}
		if ( !$title->exists() ) {
			$this->dieWithError(
				new RawMessage( "File page \"{$title->getPrefixedText()}\" does not exist." ),
				'not_found'
			);
		}

		$license = $params['license'] !== null ? strtoupper( trim( (string)$params['license'] ) ) : null;
		if ( $license === '' ) {
			$license = null;
		}
		if ( $license !== null && !ImageFactsRequest::isValidLicenseId( $license ) ) {
			$this->dieWithError(
				new RawMessage( "license \"{$license}\" is not an item ID (Q<n>)." ),
				'invalid_license'
			);
		}

		$label = (string)preg_replace( '/\.[^.]+$/', '', $title->getText() );
		$itemId = ImageItemCreator::createOrReuse(
			$this->config,
			$this->getUser(),
			$label,
			trim( (string)( $params['description'] ?? '' ) ),
			$title->getPrefixedText(),
			$license,
			trim( (string)( $params['author'] ?? '' ) ),
			trim( (string)( $params['licenseInfo'] ?? '' ) ),
			trim( (string)( $params['sourceUrl'] ?? '' ) ),
			$params['summary'] ?? 'Set image facts'
		);

		if ( $itemId === null ) {
			$this->dieWithError(
				new RawMessage(
					'The image item vocabulary (image class and properties) is not configured on this wiki.'
				),
				'imagefacts-vocabulary-missing'
			);
		}

		$this->getResult()->addValue( null, 'imagefacts', [
			'itemId' => $itemId,
			'filePage' => $title->getPrefixedText(),
		] );
	}

	public function getAllowedParams() {
		return [
			'filename' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
				self::PARAM_MAX_BYTES => 255,
			],
			'description' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_MAX_BYTES => 2000,
			],
			'license' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_MAX_BYTES => 16,
			],
			'author' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_MAX_BYTES => 250,
			],
			'licenseInfo' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_MAX_BYTES => 250,
			],
			'sourceUrl' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_MAX_BYTES => 500,
			],
			'summary' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_MAX_BYTES => 255,
			],
		];
	}

	public function isWriteMode() {
		return true;
	}

	public function mustBePosted() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function getModuleDescription() {
		return 'Creates or reuses the sitelinked image item for an uploaded File: page, writing the license, image author, additional license information and source URL statements (the API counterpart of the Special:Upload itemization).';
	}
}
