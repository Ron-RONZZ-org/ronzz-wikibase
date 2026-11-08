<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;

/**
 * The multi-access record helpers shared by the AddSource/UpdateSource forms
 * and SourceFlowService.
 *
 * An access entry is:
 *   [
 *     'type' => 'url'|'file',
 *     'url' => 'https://…',              // type=url
 *     'fileTitle' => 'File:Foo.pdf',      // type=file (DB key or File: title)
 *     'public' => '1'|'',                 // '' = login-required
 *     'license' => 'Q…',                  // file, public only
 *     'archive' => '1'|'',                // url only
 *     'archivedTitle' => 'Source:…/archivedText', // server-managed
 *   ]
 *
 * Storage: one statement per entry on the configured `accessUrl` or `file`
 * property, with qualifiers for the public/login status, the license (files)
 * and the archived-text subpage (URLs). The legacy single-access shape
 * (one access URL/file + a top-level license) is read unchanged and written
 * only when the record has no `accessEntries` key.
 *
 * @license GPL-2.0-or-later
 */
final class AccessEntries {

	public const TYPE_URL = 'url';
	public const TYPE_FILE = 'file';
	public const STATUS_PUBLIC = 'public';
	public const STATUS_LOGIN = 'login';

	/**
	 * Normalize a submitted list: trim strings, cast booleans, drop empty
	 * rows. A JSON string (the hidden HTMLForm field) is decoded.
	 *
	 * @param mixed $raw
	 * @return list<array<string,string>>
	 */
	public static function normalize( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( !is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $row ) {
			if ( !is_array( $row ) ) {
				continue;
			}
			$type = strtolower( trim( (string)( $row['type'] ?? '' ) ) );
			if ( $type !== self::TYPE_URL && $type !== self::TYPE_FILE ) {
				continue;
			}
			$entry = [
				'type' => $type,
				'public' => !empty( $row['public'] ) ? '1' : '',
			];
			if ( $type === self::TYPE_URL ) {
				$entry['url'] = trim( (string)( $row['url'] ?? '' ) );
				if ( $entry['url'] === '' ) {
					continue;
				}
				$entry['archive'] = !empty( $row['archive'] ) ? '1' : '';
				$archived = trim( (string)( $row['archivedTitle'] ?? '' ) );
				if ( $archived !== '' ) {
					$entry['archivedTitle'] = $archived;
				}
			} else {
				$entry['fileTitle'] = trim( (string)( $row['fileTitle'] ?? '' ) );
				if ( $entry['fileTitle'] === '' ) {
					continue;
				}
				$license = trim( (string)( $row['license'] ?? '' ) );
				if ( $license !== '' ) {
					$entry['license'] = $license;
				}
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Validate a normalized list. Returns a user-facing error, or null.
	 *
	 * @param list<array<string,string>> $entries
	 */
	public static function validate( array $entries, EmbeddableContentConfig $config ): ?string {
		$props = $config->sourcePropertyIds();
		$hasStatus = isset( $props['accessStatus'] );
		$hasArchive = isset( $props['archivedText'] );
		$hasLicense = isset( $props['license'] );
		foreach ( $entries as $entry ) {
			if ( $entry['type'] === self::TYPE_URL ) {
				if ( !self::isHttpUrl( $entry['url'] ) ) {
					return 'access entry URL must be an http(s) URL.';
				}
				if ( !empty( $entry['archive'] ) && !$hasArchive ) {
					return 'this instance has no property for archived access text.';
				}
				continue;
			}
			$title = self::fileTitle( $entry['fileTitle'] );
			if ( $title === null || !$title->exists() ) {
				return "access file \"{$entry['fileTitle']}\" does not exist on this wiki.";
			}
			if ( !empty( $entry['public'] ) ) {
				if ( !$hasLicense ) {
					return 'this instance has no license property for public access files.';
				}
				$license = trim( (string)( $entry['license'] ?? '' ) );
				if ( preg_match( '/^Q[1-9]\d*$/', $license ) !== 1 ) {
					return 'a public access file needs a license item.';
				}
			}
		}
		if ( !$hasStatus && $entries !== [] ) {
			return 'this instance has no property for the access status.';
		}
		return null;
	}

	/**
	 * Add the entry statements to an item (caller has already removed the
	 * managed access statements on update).
	 *
	 * @param list<array<string,string>> $entries
	 */
	public static function addStatements(
		Item $item,
		array $entries,
		EmbeddableContentConfig $config,
		?GuidGenerator $guidGenerator = null
	): void {
		$props = $config->sourcePropertyIds();
		$statusProp = $props['accessStatus'] ?? null;
		$archiveProp = $props['archivedText'] ?? null;
		$licenseProp = $props['license'] ?? null;
		foreach ( $entries as $entry ) {
			$mainProp = $entry['type'] === self::TYPE_URL
				? ( $props['accessUrl'] ?? null )
				: ( $props['file'] ?? null );
			if ( $mainProp === null ) {
				continue;
			}
			$value = $entry['type'] === self::TYPE_URL
				? (string)$entry['url']
				: self::fileUrl( (string)$entry['fileTitle'] );
			if ( $value === null ) {
				continue;
			}
			$guid = $guidGenerator !== null && $item->getId() !== null
				? $guidGenerator->newGuid( $item->getId() )
				: null;
			$statement = new Statement(
				new PropertyValueSnak(
					new NumericPropertyId( $mainProp ),
					new StringValue( $value )
				),
				null,
				null,
				$guid
			);
			$qualifiers = new SnakList();
			if ( $statusProp !== null ) {
				$qualifiers->addSnak( new PropertyValueSnak(
					new NumericPropertyId( $statusProp ),
					new StringValue( !empty( $entry['public'] ) ? self::STATUS_PUBLIC : self::STATUS_LOGIN )
				) );
			}
			if ( $entry['type'] === self::TYPE_FILE
				&& !empty( $entry['public'] )
				&& $licenseProp !== null
				&& preg_match( '/^Q[1-9]\d*$/', (string)( $entry['license'] ?? '' ) ) === 1
			) {
				$qualifiers->addSnak( new PropertyValueSnak(
					new NumericPropertyId( $licenseProp ),
					new EntityIdValue( new ItemId( (string)$entry['license'] ) )
				) );
			}
			if ( $entry['type'] === self::TYPE_URL
				&& $archiveProp !== null
				&& !empty( $entry['archivedTitle'] )
			) {
				$qualifiers->addSnak( new PropertyValueSnak(
					new NumericPropertyId( $archiveProp ),
					new StringValue( (string)$entry['archivedTitle'] )
				) );
			}
			$statement->setQualifiers( $qualifiers );
			$item->getStatements()->addStatement( $statement );
		}
	}

	/**
	 * Read the item's access entries (multi + legacy shapes). File entries
	 * inherit the item's top-level license when they have no license
	 * qualifier (legacy single-access items).
	 *
	 * @return list<array<string,string>>
	 */
	public static function fromItem( Item $item, EmbeddableContentConfig $config ): array {
		$props = $config->sourcePropertyIds();
		$statusProp = $props['accessStatus'] ?? null;
		$archiveProp = $props['archivedText'] ?? null;
		$licenseProp = $props['license'] ?? null;
		$legacyLicense = self::topLevelLicense( $item, $licenseProp );
		$out = [];
		foreach ( self::statements( $item, $props['accessUrl'] ?? null ) as $statement ) {
			$url = self::stringValue( $statement );
			if ( $url === '' ) {
				continue;
			}
			$out[] = [
				'type' => self::TYPE_URL,
				'url' => $url,
				'public' => self::isPublic( $statement, $statusProp ) ? '1' : '',
				'archive' => self::qualifierString( $statement, $archiveProp ) !== '' ? '1' : '',
				'archivedTitle' => self::qualifierString( $statement, $archiveProp ),
			];
		}
		foreach ( self::statements( $item, $props['file'] ?? null ) as $statement ) {
			$url = self::stringValue( $statement );
			$title = $url !== '' ? self::titleFromUrl( $url ) : null;
			if ( $title === null ) {
				continue;
			}
			$license = self::qualifierEntityId( $statement, $licenseProp ) ?: $legacyLicense;
			$out[] = [
				'type' => self::TYPE_FILE,
				'fileTitle' => $title->getPrefixedText(),
				'public' => self::isPublic( $statement, $statusProp ) ? '1' : '',
				'license' => $license,
			];
		}
		return $out;
	}

	/**
	 * Persist the archived-text subpage title on each URL entry's access
	 * statement (qualifier). Caller saves the item afterwards.
	 *
	 * @param list<array<string,string>> $entries
	 */
	public static function setArchivedTitles(
		Item $item,
		array $entries,
		EmbeddableContentConfig $config
	): void {
		$props = $config->sourcePropertyIds();
		$accessProp = $props['accessUrl'] ?? null;
		$archiveProp = $props['archivedText'] ?? null;
		if ( $accessProp === null || $archiveProp === null ) {
			return;
		}
		try {
			$accessId = new NumericPropertyId( $accessProp );
			$archiveId = new NumericPropertyId( $archiveProp );
		} catch ( \Throwable $e ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( $entry['type'] !== self::TYPE_URL || empty( $entry['archivedTitle'] ) ) {
				continue;
			}
			foreach ( $item->getStatements()->getByPropertyId( $accessId ) as $statement ) {
				$snak = $statement->getMainSnak();
				$value = $snak instanceof PropertyValueSnak ? $snak->getDataValue() : null;
				if ( !$value instanceof StringValue || $value->getValue() !== $entry['url'] ) {
					continue;
				}
				$qualifiers = new SnakList();
				foreach ( $statement->getQualifiers() as $qualifier ) {
					if ( !$qualifier->getPropertyId()->equals( $archiveId ) ) {
						$qualifiers->addSnak( $qualifier );
					}
				}
				$qualifiers->addSnak( new PropertyValueSnak(
					$archiveId,
					new StringValue( (string)$entry['archivedTitle'] )
				) );
				$statement->setQualifiers( $qualifiers );
				break;
			}
		}
	}

	/** @return string[] the access URL/file property ids managed by the form. */
	public static function managedPropertyIds( EmbeddableContentConfig $config ): array {
		$props = $config->sourcePropertyIds();
		return array_values( array_filter( [
			$props['accessUrl'] ?? null,
			$props['file'] ?? null,
			$props['license'] ?? null,
		] ) );
	}

	/** @return Statement[] */
	private static function statements( Item $item, ?string $propertyId ): array {
		if ( $propertyId === null ) {
			return [];
		}
		try {
			$id = new NumericPropertyId( $propertyId );
		} catch ( \Throwable $e ) {
			return [];
		}
		$out = [];
		foreach ( $item->getStatements()->getByPropertyId( $id ) as $statement ) {
			$out[] = $statement;
		}
		return $out;
	}

	private static function stringValue( Statement $statement ): string {
		$snak = $statement->getMainSnak();
		$value = $snak instanceof PropertyValueSnak ? $snak->getDataValue() : null;
		return $value instanceof StringValue ? (string)$value->getValue() : '';
	}

	private static function qualifierString( Statement $statement, ?string $propertyId ): string {
		if ( $propertyId === null ) {
			return '';
		}
		$value = self::qualifierValue( $statement, $propertyId );
		return $value instanceof StringValue ? (string)$value->getValue() : '';
	}

	private static function qualifierEntityId( Statement $statement, ?string $propertyId ): string {
		if ( $propertyId === null ) {
			return '';
		}
		$value = self::qualifierValue( $statement, $propertyId );
		return $value instanceof EntityIdValue ? $value->getEntityId()->getSerialization() : '';
	}

	private static function qualifierValue( Statement $statement, string $propertyId ) {
		try {
			$id = new NumericPropertyId( $propertyId );
		} catch ( \Throwable $e ) {
			return null;
		}
		foreach ( $statement->getQualifiers() as $snak ) {
			if ( $snak instanceof PropertyValueSnak && $snak->getPropertyId()->equals( $id ) ) {
				return $snak->getDataValue();
			}
		}
		return null;
	}

	private static function isPublic( Statement $statement, ?string $statusProp ): bool {
		$status = self::qualifierString( $statement, $statusProp );
		return $status !== self::STATUS_LOGIN;
	}

	private static function topLevelLicense( Item $item, ?string $propertyId ): string {
		if ( $propertyId === null ) {
			return '';
		}
		foreach ( self::statements( $item, $propertyId ) as $statement ) {
			// topLevelLicense reads the MAIN snak, not a qualifier.
			$snak = $statement->getMainSnak();
			$dataValue = $snak instanceof PropertyValueSnak ? $snak->getDataValue() : null;
			if ( $dataValue instanceof EntityIdValue ) {
				return $dataValue->getEntityId()->getSerialization();
			}
		}
		return '';
	}

	/**
	 * Apply the internal-use marker/category to every file entry: a
	 * non-public entry is gated to logged-in readers; a public one clears a
	 * stale marker. A failure is a form error (never silent).
	 *
	 * @param list<array<string,string>> $entries
	 */
	public static function applyFileMarkers( array $entries, \MediaWiki\User\User $user ): ?string {
		foreach ( $entries as $entry ) {
			if ( $entry['type'] !== self::TYPE_FILE ) {
				continue;
			}
			$title = self::fileTitle( (string)( $entry['fileTitle'] ?? '' ) );
			if ( $title === null ) {
				continue;
			}
			try {
				\EmbeddableContent\Spec\InternalFile::setInternal(
					$title,
					empty( $entry['public'] ),
					$user
				);
			} catch ( \Throwable $e ) {
				return 'could not update the access-file marker: ' . $e->getMessage();
			}
		}
		return null;
	}

	public static function isHttpUrl( string $url ): bool {
		return preg_match( '#^https?://#i', $url ) === 1;
	}

	public static function fileTitle( string $value ): ?Title {
		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}
		if ( stripos( $value, 'File:' ) === 0 ) {
			$title = Title::newFromText( $value );
		} else {
			$title = Title::makeTitle( NS_FILE, $value );
		}
		return $title !== null && $title->inNamespace( NS_FILE ) ? $title : null;
	}

	private static function fileUrl( string $value ): ?string {
		$title = self::fileTitle( $value );
		return $title !== null ? $title->getFullURL() : null;
	}

	private static function titleFromUrl( string $url ): ?Title {
		$parts = parse_url( $url );
		if ( !is_array( $parts ) ) {
			return null;
		}
		$candidate = '';
		if ( isset( $parts['query'] ) ) {
			parse_str( (string)$parts['query'], $query );
			$candidate = is_string( $query['title'] ?? null ) ? $query['title'] : '';
		}
		if ( $candidate === '' ) {
			$path = (string)( $parts['path'] ?? '' );
			$candidate = $path !== '' ? basename( $path ) : '';
		}
		if ( $candidate === '' || $candidate === '/' ) {
			return null;
		}
		$candidate = rawurldecode( $candidate );
		if ( stripos( $candidate, 'File:' ) === 0 ) {
			$title = Title::newFromText( $candidate );
		} else {
			$title = Title::makeTitle( NS_FILE, $candidate );
		}
		return $title !== null && $title->inNamespace( NS_FILE ) ? $title : null;
	}

}
