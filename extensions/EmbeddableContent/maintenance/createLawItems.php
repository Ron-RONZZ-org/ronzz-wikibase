<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- maintenance entry point

declare( strict_types = 1 );

use EmbeddableContent\Flow\LawItemWriter;
use EmbeddableContent\Store\LawStore;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false || $IP === '' ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Create the entity items for the law rows an import loaded.
 *
 * The article import and the item creation are separable: a failed item
 * phase must not force a 500k-file re-walk. Existing legacy AddSource items
 * are reused by their old label and get the official legislation number.
 *
 *   php maintenance/run.php .../createLawItems.php [--limit=500]
 */
class CreateLawItems extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Create the entity items for the imported law rows.' );
		$this->addOption( 'limit', 'Maximum law rows to process.', false, true );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		/** @var LawStore $store */
		$store = $services->get( 'EmbeddableContent.LawStore' );
		/** @var LawItemWriter $writer */
		$writer = $services->get( 'EmbeddableContent.LawItemWriter' );
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		if ( $user === null ) {
			$this->fatalError( 'cannot resolve the Maintenance script user' );
		}
		$limit = (int)( $this->getOption( 'limit' ) ?: 500 );
		$created = 0;
		$reused = 0;
		$failed = 0;
		foreach ( $store->listLaws( null, $limit ) as $row ) {
			$law = $store->getLaw( (string)$row['law_key'] );
			if ( $law === null ) {
				$failed++;
				continue;
			}
			if ( trim( (string)( $law['item_id'] ?? '' ) ) !== '' ) {
				$reused++;
				continue;
			}
			$id = $writer->ensureLawItem( $law, $user );
			if ( $id === null ) {
				$failed++;
				$this->output( 'FAILED ' . $law['law_key'] . "\n" );
				continue;
			}
			$store->setLawItemId( (string)$law['law_key'], $id );
			$created++;
			$this->output( $law['law_key'] . ' -> ' . $id . "\n" );
		}
		$this->output( "created=$created reused=$reused failed=$failed\n" );
	}
}

$maintClass = CreateLawItems::class;
require_once RUN_MAINTENANCE_IF_MAIN;
