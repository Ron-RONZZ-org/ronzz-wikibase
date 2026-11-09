<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Upload\ImageFactsRequest;
use EmbeddableContent\Upload\ImageItemCreator;
use MediaWiki\Api\ApiBase;
use MediaWiki\Language\RawMessage;
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

		$clearFields = ImageFactsRequest::parseClearFields( (string)( $params['clear'] ?? '' ) );
		foreach ( $clearFields as $field ) {
			if ( !ImageFactsRequest::isClearableField( $field ) ) {
				$this->dieWithError(
					new RawMessage( "clear field \"{$field}\" is not one of: "
						. implode( ', ', ImageFactsRequest::CLEARABLE_FIELDS ) . '.' ),
					'invalid_clear'
				);
			}
			if ( trim( (string)( $params[$field] ?? '' ) ) !== '' ) {
				$this->dieWithError(
					new RawMessage( "clear field \"{$field}\" was also given a value; use one or the other." ),
					'clear_conflict'
				);
			}
		}
		if ( $clearFields !== [] && $params['mode'] !== 'update' ) {
			$this->dieWithError(
				new RawMessage( 'clear requires mode=update.' ),
				'clear_requires_update'
			);
		}

		$authorEntity = $this->itemIdsParam( $params['authorEntity'] ?? null, 'authorEntity' );
		$sourceEntity = $this->itemIdParam( $params['sourceEntity'] ?? null, 'sourceEntity' );

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
			$params['summary'] ?? 'Set image facts',
			$params['mode'] === 'update',
			$clearFields,
			$authorEntity,
			$sourceEntity,
			trim( (string)( $params['sourceText'] ?? '' ) )
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
			'mode' => $params['mode'],
			...( $clearFields !== [] ? [ 'cleared' => $clearFields ] : [] ),
		] );
	}

	/**
	 * A Q-id API parameter, or null when blank; a non-item value is a client
	 * error (the entity comboboxes only submit ids).
	 */
	private function itemIdParam( ?string $value, string $name ): ?string {
		$value = trim( (string)$value );
		if ( $value === '' ) {
			return null;
		}
		if ( preg_match( '/^Q[1-9]\d*$/i', $value ) !== 1 ) {
			$this->dieWithError(
				new RawMessage( "{$name} \"{$value}\" is not an item ID (Q<n>)." ),
				'invalid_' . strtolower( $name )
			);
		}
		return strtoupper( $value );
	}

	/** Comma/semicolon-separated item ids (the multi-value author field). */
	private function itemIdsParam( ?string $value, string $name ): ?string {
		$value = trim( (string)$value );
		if ( $value === '' ) {
			return null;
		}
		$ids = [];
		foreach ( preg_split( '/[,;]/', $value ) ?: [] as $token ) {
			$token = trim( $token );
			if ( preg_match( '/^Q[1-9]\d*$/i', $token ) !== 1 ) {
				$this->dieWithError(
					new RawMessage( "{$name} \"{$token}\" is not an item ID (Q<n>)." ),
					'invalid_' . strtolower( $name )
				);
			}
			$id = strtoupper( $token );
			if ( !in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return implode( ', ', $ids );
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
			// Entity author/source (the item-valued provenance statements);
			// `author` stays the free-text image-author string.
			'authorEntity' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				// Several comma-separated Q-ids.
				self::PARAM_MAX_BYTES => 500,
			],
			'sourceEntity' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_MAX_BYTES => 16,
			],
			'sourceText' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_MAX_BYTES => 500,
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
			// create (default) = create/reuse and never rewrite an existing
			// item; update = replace the statements for each PROVIDED field
			// (blank keeps the current statement), for update-file.
			'mode' => [
				self::PARAM_TYPE => [ 'create', 'update' ],
				self::PARAM_REQUIRED => false,
				self::PARAM_DFLT => 'create',
			],
			// Comma-separated fact fields to remove in mode=update; a field
			// cannot be both cleared and given a value.
			'clear' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
				self::PARAM_MAX_BYTES => 200,
				self::PARAM_DFLT => '',
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
		return 'Creates, reuses or updates the sitelinked image item for an uploaded File: page, writing the license, image author, image source, additional license information, source URL and the entity author/source (attributed to / source) statements. The API counterpart of the Special:Upload itemization: mode=update replaces the statements for the fields provided, a blank field keeps the current statement, and clear removes them. When no entity author is given but an entity source is, the source item\'s attributed-to authors are inherited.';
	}
}
