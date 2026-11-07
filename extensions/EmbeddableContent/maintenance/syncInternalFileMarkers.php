<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- entry point name

declare( strict_types = 1 );

namespace EmbeddableContent\Maintenance;

use EmbeddableContent\Spec\InternalFile;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Rebuilds the internal-media marker directory from the File: pages'
 * `Category:Internal use only files` membership.
 *
 * The markers are the filesystem flag nginx checks; a full restore (or a
 * lost marker directory) would otherwise serve internal files publicly.
 * This script only CREATES missing markers — it never removes one (the
 * Add/Update flow does that explicitly when the checkbox is cleared).
 *
 * Usage: sudo -u ronzz php maintenance/run.php \
 *            extensions/EmbeddableContent/maintenance/syncInternalFileMarkers.php [--dry-run]
 *
 * @license GPL-2.0-or-later
 */
class SyncInternalFileMarkers extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Rebuild internal-file media markers from the category (restore recovery).' );
		$this->addOption( 'dry-run', 'List the files that would be marked, change nothing.' );
	}

	public function execute(): void {
		$category = InternalFile::categoryName();
		$categoryTitle = Title::makeTitle( NS_CATEGORY, $category );
		if ( $categoryTitle === null ) {
			$this->fatalError( "Invalid internal-file category configuration: {$category}" );
		}

		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'page_title' ] )
			->from( 'categorylinks' )
			->join( 'page', null, 'cl_from = page_id' )
			->join( 'linktarget', null, 'cl_target_id = lt_id' )
			->where( [
				'lt_namespace' => NS_CATEGORY,
				'lt_title' => $categoryTitle->getDBkey(),
				'page_namespace' => NS_FILE,
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$dryRun = (bool)$this->getOption( 'dry-run' );
		$created = 0;
		$failed = 0;
		foreach ( $rows as $row ) {
			$title = Title::makeTitle( NS_FILE, $row->page_title );
			if ( $title === null ) {
				continue;
			}
			if ( $dryRun ) {
				$this->output( "would mark {$title->getPrefixedText()}\n" );
				continue;
			}
			try {
				if ( InternalFile::syncOne( $title ) ) {
					$created++;
					$this->output( "marked {$title->getPrefixedText()}\n" );
				}
			} catch ( \Throwable $e ) {
				$failed++;
				$this->error( "failed {$title->getPrefixedText()}: {$e->getMessage()}\n" );
			}
		}

		$this->output( "done: {$created} created, {$failed} failed\n" );
		if ( $failed > 0 ) {
			$this->fatalError( 'Some markers could not be created.' );
		}
	}

}

return SyncInternalFileMarkers::class;
