<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Content\FragmentSanitizer;
use EmbeddableContent\Duration;
use MediaWiki\Request\WebRequestUpload;
use Wikibase\DataModel\Entity\Item;

/**
 * Special:UpdateSource — re-edit an existing source item (book,
 * scholarlyArticle, song, film, video, youtubeChannel, youtubeVideo,
 * website, webpage, bookExcerpt) with the exact same review fields as
 * Special:AddSource, prefilled from the item's statements; submit UPDATES
 * the item instead of creating a new one.
 *
 * URL: Special:UpdateSource/Q42 — the source class key is detected from the
 * item's instance-of (the /<classKey> subpage of AddSource has no analogue
 * here: one page, any source class).
 *
 * The access section is relaxed on update: an EXISTING uploaded file is
 * preserved when the mode stays file/download and no new file/URL is
 * provided (the Add* flow requires an upload because it creates the file;
 * the update only re-declares the fact).
 *
 * @license GPL-2.0-or-later
 */
class SpecialUpdateSource extends SpecialAddSource {
	/**
	 * The no-clobber managed set + replacement values come from the shared
	 * SourceFlowService (the action=addsource contract), never from a
	 * per-form copy.
	 */
	protected function updateStatementSpecs( array $record ): array {
		return $this->sourceFlow()->statementSpecs(
			\EmbeddableContent\Flow\SourceFieldMap::apiKey( $this->currentClassKey ),
			$this->flowRecord( $record )
		);
	}


	use UpdateExternalEntityFlow;

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client
	) {
		parent::__construct( $config, $client, 'UpdateSource' );
	}

	protected function updateKindKey(): string {
		return 'source';
	}

	/**
	 * The AddSource class-disambiguation label suffix (" (Book)", …) is a
	 * CREATION-time convention: an update shows the item's existing label
	 * as-is (re-adding the suffix would rename every pre-convention item on
	 * its first update; the suffix stays when it is already part of the
	 * stored label).
	 */
	protected function applyLabelSuffix(): bool {
		return false;
	}

	protected function updateClassItemId( Item $item ): ?string {
		$classIds = $this->itemClassIds( $item );
		foreach ( $this->config->sourceClasses() as $key => $id ) {
			if ( in_array( $id, $classIds, true ) ) {
				// The per-class review field layouts switch on the class key.
				$this->currentClassKey = $key;
				return $id;
			}
		}
		return null;
	}

	/**
	 * Reverse-maps a source item onto the AddSource review-record shape.
	 * The page-content fields (abstract/keywords/intro/…) live in the
	 * classic page, not the item — they are out of scope for "basic
	 * information".
	 */
	protected function recordFromItem( Item $item ): array {
		$record = [
			'title' => $this->itemLabel( $item ),
			'description' => $this->itemDescription( $item ),
			'authors' => implode( ', ', $this->entityIdsForProperty(
				$item,
				$this->config->provenancePropertyIds()['attributedTo'] ?? null
			) ),
		];

		$citation = $this->config->citationMetadataPropertyIds();
		$record['publisher'] = $this->firstEntityForProperty( $item, $citation['publisher'] ?? null );
		$record['journal'] = $this->firstEntityForProperty( $item, $citation['journal'] ?? null );
		$record['pages'] = $this->firstStringForProperty( $item, $citation['pages'] ?? null );
		$record['volume'] = $this->firstStringForProperty( $item, $citation['volume'] ?? null );
		$record['issue'] = $this->firstStringForProperty( $item, $citation['issue'] ?? null );

		$source = $this->config->sourcePropertyIds();
		$record['url'] = $this->firstStringForProperty( $item, $source['url'] ?? null );
		$record['youtubeChannelId'] = $this->firstStringForProperty( $item, $source['youtubeChannelId'] ?? null );
		$record['youtubeVideoId'] = $this->firstStringForProperty( $item, $source['youtubeVideoId'] ?? null );
		$record['chapters'] = $this->firstStringForProperty( $item, $source['chapters'] ?? null );
		$record['accessUrl'] = $this->firstStringForProperty( $item, $source['accessUrl'] ?? null );
		$record['fileTitle'] = $this->accessFileTitle( $item, $source['file'] ?? null );
		$record['license'] = $this->firstEntityForProperty( $item, $source['license'] ?? null );
		// accessMode derivation: an uploaded file → file; else a bare
		// license (no access URL) → download; else the access URL → url;
		// else not applicable. A stored download is indistinguishable from
		// a stored file (both write the file statement) — the mode only
		// matters when the user provides a NEW file/URL.
		$record['accessMode'] = $record['fileTitle'] !== ''
			? 'file'
			: ( $record['accessUrl'] !== '' ? 'url' : ( $record['license'] !== '' ? 'download' : 'na' ) );
		$record['downloadUrl'] = '';

		$year = $this->yearForProperty( $item, $this->config->provenancePropertyIds()['date'] ?? null );
		if ( $year !== null ) {
			$record['issuedYear'] = $year;
		}

		$durationSeconds = $this->quantityForProperty( $item, $source['duration'] ?? null );
		if ( $durationSeconds !== null && $durationSeconds > 0 ) {
			$record['duration'] = Duration::formatSeconds( $durationSeconds );
		}

		$record['parent'] = $this->firstEntityForProperty( $item, $source['partOf'] ?? null );

		foreach ( $this->externalIdRecordMap() as $key => $field ) {
			$record[$field] = $this->firstStringForProperty(
				$item,
				$this->config->externalIdPropertyIds()[$key] ?? null
			);
		}
		return $record;
	}

	/**
	 * The file statement value is the File: page URL (sourceProperties.file)
	 * — the record's fileTitle is the DB key, so strip the URL prefix.
	 */
	private function accessFileTitle( Item $item, ?string $propertyId ): string {
		$url = $this->firstStringForProperty( $item, $propertyId );
		if ( $url === '' ) {
			return '';
		}
		$path = (string)parse_url( $url, PHP_URL_PATH );
		$name = basename( $path );
		// "File:<name>.<ext>" (URL-encoded names decoded).
		return $name !== '' && $name !== '/'
			? 'File:' . rawurldecode( $name )
			: '';
	}

	/**
	 * Relaxed access validation for the update path: an existing uploaded
	 * file is KEPT when the mode stays file/download and no new file or
	 * download URL is provided (the Add* flow requires the upload because
	 * it creates the file — an update only re-declares the fact). A
	 * mode change to download with no URL, or to file with no file and no
	 * existing file, still errors.
	 *
	 * @param array<string,mixed> $record
	 */
	private function relaxedAccessField( array &$record ): ?string {
		$mode = (string)( $record['accessMode'] ?? 'url' );
		if ( !in_array( $mode, [ 'url', 'download', 'file', 'na' ], true ) ) {
			$mode = 'url';
		}
		$record['accessMode'] = $mode;
		if ( $mode === 'url' || $mode === 'na' ) {
			return null;
		}

		$licenseId = trim( (string)( $record['license'] ?? '' ) );
		$licenseItem = $this->parseItemId( $licenseId );
		if ( $licenseItem === null ) {
			return $this->msg( 'embeddablecontent-source-error-license' )->text();
		}
		$record['license'] = $licenseItem->getSerialization();

		try {
			if ( $mode === 'file' ) {
				$upload = $this->getRequest()->getUpload( 'wpaccessFile' );
				if ( $upload instanceof WebRequestUpload && $upload->getSize() > 0 ) {
					$title = $this->uploadAccessFileFromRequest( $record );
					if ( $title === null ) {
						return $this->msg( 'embeddablecontent-source-error-access-upload', 'unsupported file type' )->text();
					}
					$record['fileTitle'] = $title->getDBkey();
				} elseif ( empty( $record['fileTitle'] ) ) {
					// Neither a new file nor an existing one to keep.
					return $this->msg( 'embeddablecontent-source-error-access-file-required' )->text();
				}
			} else {
				$url = ( new FragmentSanitizer() )->validateUrl( (string)( $record['downloadUrl'] ?? '' ) );
				if ( $url === null ) {
					if ( !empty( $record['fileTitle'] ) ) {
						// Keep the existing download.
						return null;
					}
					return $this->msg( 'embeddablecontent-source-error-access-upload', 'invalid or unreachable URL' )->text();
				}
				$title = $this->uploadAccessFileFromUrl( $url, $record );
				if ( $title === null ) {
					return $this->msg( 'embeddablecontent-source-error-access-upload', 'unreachable or unsupported URL' )->text();
				}
				$record['fileTitle'] = $title->getDBkey();
			}
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-source-error-access-upload', $e->getMessage() )->text();
		}
		return null;
	}

	/**
	 * The access section is OPT-IN on update (users seldom change access
	 * facts): an "I will update the resource access instructions" checkbox
	 * reveals the whole section, and an unchecked submit leaves the item's
	 * access statements untouched (beforeCreate neutralizes the record
	 * keys). The Add* flow keeps the section always visible — a new source
	 * must declare its access fact at creation.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,mixed>
	 */
	protected function accessFieldSpec( array $record ): array {
		$fields = parent::accessFieldSpec( $record );
		$include = [
			'accessInclude' => [
				'type' => 'check',
				'label-message' => 'embeddablecontent-update-source-access-include',
				'default' => false,
			],
		];
		$gate = [ '===', 'accessInclude', '' ];
		foreach ( $fields as $name => $spec ) {
			$fields[$name]['hide-if'] = isset( $spec['hide-if'] )
				? [ 'OR', $gate, $spec['hide-if'] ]
				: $gate;
		}
		return $include + $fields;
	}

	/**
	 * Update-path validation: the Add* cross-field checks minus the
	 * create-time access-upload requirement (see relaxedAccessField), plus
	 * the collapsed-access neutralization (an unchecked accessInclude keeps
	 * the item's access statements exactly as they are — nothing is
	 * replaced, nothing is required).
	 *
	 * @param array<string,mixed> $record
	 */
	protected function beforeCreate( array &$record ): ?string {
		if ( empty( $record['accessInclude'] ) ) {
			foreach ( [ 'accessMode', 'accessUrl', 'downloadUrl', 'fileTitle', 'license' ] as $key ) {
				$record[$key] = '';
			}
		}
		$rawDuration = trim( (string)( $record['duration'] ?? '' ) );
		if ( $rawDuration !== '' ) {
			$seconds = Duration::parseSeconds( $rawDuration );
			if ( $seconds === null ) {
				return $this->msg( 'embeddablecontent-source-error-duration' )->text();
			}
			$record['durationSeconds'] = $seconds;
		}

		$error = $this->relaxedAccessField( $record );
		if ( $error !== null ) {
			return $error;
		}

		if ( $this->currentClassKey === 'bookExcerpt' ) {
			$error = $this->fillBookExcerptFromParent( $record );
			if ( $error !== null ) {
				return $error;
			}
		}

		$error = $this->validateAuthors( $record );
		if ( $error !== null ) {
			return $error;
		}

		if ( $this->currentClassKey === 'youtubeChannel' && empty( $record['youtubeChannelId'] ) ) {
			$record['youtubeChannelId'] = \EmbeddableContent\Fetch\YouTubeProvider::extractChannelId(
				(string)( $record['url'] ?? '' )
			) ?? '';
		}
		if ( $this->currentClassKey === 'youtubeVideo' && empty( $record['youtubeVideoId'] ) ) {
			$record['youtubeVideoId'] = \EmbeddableContent\Fetch\YouTubeProvider::extractVideoId(
				(string)( $record['url'] ?? '' )
			) ?? '';
		}

		return $this->validateParent( $record );
	}
}
