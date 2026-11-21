<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Pdf;

use DataValues\StringValue;
use EmbeddableContent\EmbeddableContentConfig;
use EmbeddableContent\Flow\StatementGuidAssigner;
use EmbeddableContent\PropertyIdFactory;
use EmbeddableContent\Spec\AccessEntries;
use EmbeddableContent\Spec\InternalFile;
use EmbeddableContent\Spec\SparqlRunner;
use MediaWiki\FileRepo\FileRepo;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\Repo\WikibaseRepo;

/**
 * PDF → Markdown extraction, storage and Source linking.
 *
 * The extractor:
 *  1. calls Apache Tika (host loopback) for the PDF's Markdown text;
 *  2. uploads/replaces the deterministic companion File
 *     `<name>.extracted.md` with the same internal-use marker as the PDF;
 *  3. creates/reuses the companion File item (instance of access file + file
 *     URL + sitelink);
 *  4. attaches the `extracted text` qualifier to every Source item that links
 *     the PDF's access-file item (explicit `$sourceItemId` or a WDQS reverse
 *     lookup).
 *
 * Best-effort at the Source-link step (WDQS may be stale); the File-level
 * extraction is the durable result and `Special:SourceText`/the API can read
 * it without a Source link.
 *
 * @license GPL-2.0-or-later
 */
final class PdfTextExtractor {

	/** The companion file's suffix. */
	public const SUFFIX = '.extracted.txt';

	/**
	 * The deterministic companion File title for a PDF.
	 */
	public static function extractedTitle( Title $pdfTitle ): ?Title {
		if ( $pdfTitle->getNamespace() !== NS_FILE ) {
			return null;
		}
		$base = (string)preg_replace( '/\.[^.]+$/', '', $pdfTitle->getText() );
		if ( $base === '' ) {
			return null;
		}
		return Title::makeTitleSafe( NS_FILE, $base . self::SUFFIX );
	}

	/**
	 * Whether a File page is a PDF that can be extracted.
	 */
	public static function isPdf( Title $title ): bool {
		if ( $title->getNamespace() !== NS_FILE ) {
			return false;
		}
		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title );
		if ( $file === false || !$file->exists() ) {
			return false;
		}
		return $file->getMimeType() === 'application/pdf'
			|| strtolower( (string)$file->getExtension() ) === 'pdf';
	}

	/**
	 * Whether the companion extraction is missing or older than the PDF.
	 */
	public static function needsExtraction( Title $pdfTitle ): bool {
		$mdTitle = self::extractedTitle( $pdfTitle );
		if ( $mdTitle === null || !$mdTitle->exists() ) {
			return true;
		}
		$pdf = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $pdfTitle );
		$md = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $mdTitle );
		if ( $pdf === false || $md === false ) {
			return true;
		}
		return $md->getTimestamp() < $pdf->getTimestamp();
	}

	/**
	 * Extract one PDF. Returns a result array; never throws for a normal
	 * extraction failure (the caller logs it).
	 *
	 * @return array{status:string,message:string,chars:int,partial:bool}
	 */
	public static function extract(
		Title $pdfTitle,
		User $user,
		?string $sourceItemId = null,
		bool $force = false
	): array {
		if ( !self::isPdf( $pdfTitle ) ) {
			return self::result( 'skip', 'not a PDF File page' );
		}
		if ( !$force && !self::needsExtraction( $pdfTitle ) ) {
			return self::result( 'skip', 'extraction is current' );
		}
		$pdfFile = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $pdfTitle );
		if ( $pdfFile === false ) {
			return self::result( 'error', 'PDF file not found' );
		}
		$path = $pdfFile->getLocalRefPath();
		if ( $path === false || !is_file( $path ) ) {
			return self::result( 'error', 'PDF bytes are not locally readable' );
		}
		$config = MediaWikiServices::getInstance()->get( 'EmbeddableContent.Config' );
		if ( !$config instanceof EmbeddableContentConfig ) {
			return self::result( 'error', 'extension config unavailable' );
		}

		try {
			$tika = new TikaClient( $config->pdfTikaEndpoint(), $config->pdfTikaTimeout() );
			$extracted = $tika->extractMarkdown( $path );
		} catch ( TikaException $e ) {
			return self::result( 'error', $e->getMessage() );
		}
		$markdown = trim( $extracted['markdown'] );
		if ( $markdown === '' ) {
			return self::result( 'error', 'Tika returned no text' );
		}

		$mdTitle = self::extractedTitle( $pdfTitle );
		if ( $mdTitle === null ) {
			return self::result( 'error', 'could not derive the companion file title' );
		}
		$uploadError = self::uploadMarkdown( $mdTitle, $markdown, $pdfTitle, $extracted['partial'], $user );
		if ( $uploadError !== null ) {
			return self::result( 'error', $uploadError );
		}

		$extractedItemId = self::ensureFileItem( $config, $mdTitle, $pdfTitle, $user );
		$linkError = $extractedItemId !== null
			? self::linkSources( $config, $pdfTitle, $extractedItemId, $sourceItemId, $user )
			: 'companion File item could not be created';

		return [
			'status' => 'ok',
			'message' => $linkError ?? 'extracted and linked',
			'chars' => mb_strlen( $markdown ),
			'partial' => $extracted['partial'],
		];
	}

	/**
	 * The extracted Markdown and its metadata, or null when there is no
	 * companion file.
	 *
	 * @return array{text:string,updated:string,partial:bool}|null
	 */
	public static function read( Title $pdfTitle ): ?array {
		$mdTitle = self::extractedTitle( $pdfTitle );
		if ( $mdTitle === null || !$mdTitle->exists() ) {
			return null;
		}
		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $mdTitle );
		if ( $file === false || !$file->exists() ) {
			return null;
		}
		$path = $file->getLocalRefPath();
		if ( $path === false || !is_file( $path ) ) {
			return null;
		}
		$text = file_get_contents( $path );
		if ( $text === false ) {
			return null;
		}
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $mdTitle );
		$content = $page->getContent();
		$pageText = $content !== null ? $content->getText() : '';
		return [
			'text' => $text,
			'updated' => (string)$file->getTimestamp(),
			'partial' => str_contains( (string)$pageText, 'partial extraction' ),
		];
	}

	/**
	 * PDF File pages, for the backfill scan.
	 *
	 * @return Title[]
	 */
	public static function pdfTitles( int $limit = 50 ): array {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$rows = $dbr->select(
			'image',
			[ 'img_name' ],
			[ 'img_major_mime' => 'application', 'img_minor_mime' => 'pdf' ],
			__METHOD__,
			[ 'LIMIT' => max( 1, $limit ), 'ORDER BY' => 'img_timestamp DESC' ]
		);
		$titles = [];
		foreach ( $rows as $row ) {
			$title = Title::makeTitleSafe( NS_FILE, (string)$row->img_name );
			if ( $title !== null ) {
				$titles[] = $title;
			}
		}
		return $titles;
	}

	/**
	 * @return string|null error, or null on success
	 */
	private static function uploadMarkdown(
		Title $mdTitle,
		string $markdown,
		Title $pdfTitle,
		bool $partial,
		User $user
	): ?string {
		$tmp = tempnam( wfTempDir(), 'wbpdftext' );
		if ( $tmp === false ) {
			return 'could not create a temporary file';
		}
		if ( file_put_contents( $tmp, $markdown ) === false ) {
			@unlink( $tmp );
			return 'could not write the temporary extraction file';
		}
		try {
			$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
			$mdFile = $repo->newFile( $mdTitle );
			if ( $mdFile === null ) {
				return 'could not create the companion File object';
			}
			$pageText = 'Extracted text of [[File:' . $pdfTitle->getText() . ']]'
				. ' (Markdown, generated by Apache Tika'
				. ( $partial ? '; partial extraction' : '' ) . ").\n";
			$status = $mdFile->upload(
				$tmp,
				wfMessage( 'embeddablecontent-pdftext-upload-summary' )->inContentLanguage()->text(),
				$pageText,
				FileRepo::OVERWRITE,
				false,
				false,
				$user
			);
			if ( !$status->isOK() ) {
				return 'companion upload failed: ' . $status->getMessage()->text();
			}
		} finally {
			@unlink( $tmp );
		}
		// Mirror the source PDF's internal-use marker/category.
		try {
			InternalFile::setInternal( $mdTitle, InternalFile::isInternal( $pdfTitle ), $user );
		} catch ( \Throwable $e ) {
			wfLogWarning( 'PdfTextExtractor: internal marker failed: ' . $e->getMessage() );
		}
		return null;
	}

	/**
	 * Create/reuse the companion File item (sitelinked to the .md page).
	 */
	private static function ensureFileItem(
		EmbeddableContentConfig $config,
		Title $mdTitle,
		Title $pdfTitle,
		User $user
	): ?string {
		$store = WikibaseRepo::getStore()->newSiteLinkStore();
		$existing = $store->getItemIdForLink( 'wikibase', $mdTitle->getPrefixedText() );
		if ( $existing !== null ) {
			return $existing->getSerialization();
		}
		$item = new Item();
		$label = (string)preg_replace( '/\.[^.]+$/', '', $pdfTitle->getText() ) . ' (extracted text)';
		$item->setLabel( 'en', $label );
		$item->setDescription( 'en', 'Markdown text extracted from ' . $pdfTitle->getPrefixedText() );
		$class = $config->accessFileClasses()['accessFile'] ?? null;
		if ( $class !== null ) {
			$item->getStatements()->addNewStatement( new PropertyValueSnak(
				PropertyIdFactory::create( $config->instanceOfPropertyId() ),
				new EntityIdValue( new ItemId( $class ) )
			) );
		}
		$fileProp = $config->sourcePropertyIds()['file'] ?? null;
		if ( $fileProp !== null ) {
			$item->getStatements()->addNewStatement( new PropertyValueSnak(
				PropertyIdFactory::create( $fileProp ),
				new StringValue( $mdTitle->getFullURL() )
			) );
		}
		$summary = wfMessage( 'embeddablecontent-pdftext-item-summary' )->inContentLanguage()->text();
		try {
			$entityStore = WikibaseRepo::getEntityStore();
			$entityStore->saveEntity( $item, $summary, $user, EDIT_NEW );
			StatementGuidAssigner::ensureGuids( $item, new GuidGenerator() );
			$entityStore->saveEntity( $item, $summary, $user, EDIT_UPDATE );
			$item->getSiteLinkList()->setNewSiteLink( 'wikibase', $mdTitle->getPrefixedText() );
			$entityStore->saveEntity( $item, $summary, $user, EDIT_UPDATE );
			$store->saveLinksOfItem( $item );
		} catch ( \Throwable $e ) {
			wfLogWarning( 'PdfTextExtractor: companion item failed: ' . $e->getMessage() );
			return null;
		}
		return $item->getId() !== null ? $item->getId()->getSerialization() : null;
	}

	/**
	 * Attach the qualifier to the Source(s) linking the PDF's access item.
	 *
	 * @return string|null a warning when the link step could not run
	 */
	private static function linkSources(
		EmbeddableContentConfig $config,
		Title $pdfTitle,
		string $extractedItemId,
		?string $sourceItemId,
		User $user
	): ?string {
		$pdfItemId = WikibaseRepo::getStore()->newSiteLinkStore()
			->getItemIdForLink( 'wikibase', $pdfTitle->getPrefixedText() );
		if ( $pdfItemId === null ) {
			return 'the PDF has no sitelinked access-file item; the extraction is File-only';
		}
		$pdfItem = $pdfItemId->getSerialization();
		$sourceIds = $sourceItemId !== null ? [ strtoupper( trim( $sourceItemId ) ) ] : self::sourceIdsFor( $config, $pdfItem );
		if ( $sourceIds === [] ) {
			return 'no Source links this access file; the extraction is File-only';
		}
		$summary = wfMessage( 'embeddablecontent-pdftext-link-summary' )->inContentLanguage()->text();
		$linked = 0;
		foreach ( $sourceIds as $sourceId ) {
			if ( preg_match( '/^Q[1-9]\d*$/', $sourceId ) !== 1 ) {
				continue;
			}
			try {
				$source = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $sourceId ) );
				if ( !$source instanceof Item ) {
					continue;
				}
				if ( !AccessEntries::attachExtractedText( $source, $config, $pdfItem, $extractedItemId ) ) {
					continue;
				}
				WikibaseRepo::getEntityStore()->saveEntity( $source, $summary, $user, EDIT_UPDATE );
				$linked++;
			} catch ( \Throwable $e ) {
				wfLogWarning( 'PdfTextExtractor: source ' . $sourceId . ' link failed: ' . $e->getMessage() );
			}
		}
		return $linked > 0 ? null : 'no Source statement accepted the extracted-text qualifier';
	}

	/**
	 * The Source items whose `has access file` points at the PDF's item.
	 *
	 * @return string[]
	 */
	private static function sourceIdsFor( EmbeddableContentConfig $config, string $pdfItemId ): array {
		$endpoint = $config->sparqlUrl();
		$hasProp = $config->sourcePropertyIds()['hasAccessFile'] ?? null;
		$server = $GLOBALS['wgServer'] ?? '';
		if ( $endpoint === null || $hasProp === null || !is_string( $server ) || $server === '' ) {
			return [];
		}
		$wd = rtrim( $server, '/' ) . '/entity/';
		$wdt = str_replace( '/entity/', '/prop/direct/', $wd );
		$query = "SELECT ?source WHERE { ?source <{$wdt}{$hasProp}> <{$wd}{$pdfItemId}> . }";
		$rows = SparqlRunner::select( $endpoint, $query );
		if ( $rows === null ) {
			return [];
		}
		$ids = [];
		foreach ( $rows as $row ) {
			$uri = (string)( $row['source']['value'] ?? '' );
			if ( preg_match( '/(Q[1-9]\d*)$/', $uri, $m ) === 1 ) {
				$ids[$m[1]] = $m[1];
			}
		}
		return array_values( $ids );
	}

	/** @return array{status:string,message:string,chars:int,partial:bool} */
	private static function result( string $status, string $message ): array {
		return [ 'status' => $status, 'message' => $message, 'chars' => 0, 'partial' => false ];
	}

}
