<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- maintenance entry point

declare( strict_types = 1 );

use EmbeddableContent\Flow\LawItemWriter;
use EmbeddableContent\Law\CassParser;
use EmbeddableContent\Law\ConstitParser;
use EmbeddableContent\Law\JadeParser;
use EmbeddableContent\Law\LawImportException;
use EmbeddableContent\Law\LawImporter;
use EmbeddableContent\Law\LawSourceParser;
use EmbeddableContent\Law\LegiParser;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false || $IP === '' ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Import an extracted official open-data tree into wb_law_*.
 *
 *   # LEGI baseline (codes only; the v1 pilot):
 *   php maintenance/run.php .../importLawCorpus.php \
 *     --dir=/srv/law-corpus/fr/extracted/20261108 --mode=baseline
 *
 *   # LEGI daily increment:
 *   php maintenance/run.php .../importLawCorpus.php \
 *     --dir=/srv/law-corpus/fr/increments/LEGI_20261122 --mode=increment
 *
 *   # Constitutional bloc (an extracted TNC subtree, LEGI parser):
 *   php maintenance/run.php .../importLawCorpus.php \
 *     --dir=/srv/law-corpus/fr/constitution --source=legi --all-legi
 *
 *   # Conseil constitutionnel decisions:
 *   php maintenance/run.php .../importLawCorpus.php \
 *     --dir=/srv/law-corpus/constit/global --source=constit
 *
 *   # Cour de cassation decisions:
 *   php maintenance/run.php .../importLawCorpus.php \
 *     --dir=/srv/law-corpus/cass/global --source=cass
 *
 *   # JADE (point --dir at publie or inedit):
 *   php maintenance/run.php .../importLawCorpus.php \
 *     --dir=/srv/law-corpus/jade/publie --source=jade
 *
 * Legal items are created entity-only (no Source: pages). Bulk item creation
 * is limited to LEGI (the codes and the constitutional texts); jurisprudence
 * items are created on first citation (`law-cite`).
 */
class ImportLawCorpus extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Import an extracted law-corpus tree into the wb_law_* store.' );
		$this->addOption( 'dir', 'Extracted archive root for the selected source.', true, true );
		$this->addOption( 'mode', 'baseline|increment (default baseline).', false, true );
		$this->addOption( 'source', 'legi|constit|cass|jade (default legi).', false, true );
		$this->addOption( 'all-legi', 'Import the whole extracted LEGI tree (overrides --codes-only).' );
		$this->addOption( 'include-historical', 'Keep the historical LEGI versions (default: in-force only).' );
		$this->addOption( 'limit', 'Stop after N article rows (testing).', false, true );
		$this->addOption( 'create-items', 'Create the law entity items (default: on for legi, off for jurisprudence).' );
		$this->addOption( 'no-create-items', 'Do not create law entity items.' );
		$this->addOption( 'prune', 'After a LEGI import, delete the non-VIGUEUR historical rows.' );
		$this->addOption( 'dry-run', 'Parse and count without writing.' );
		$this->addOption( 'user', 'User to attribute item creation to.', false, true );
	}

	public function execute() {
		$dir = (string)$this->getOption( 'dir' );
		$mode = strtolower( (string)( $this->getOption( 'mode' ) ?: 'baseline' ) );
		if ( !in_array( $mode, [ 'baseline', 'increment' ], true ) ) {
			$this->fatalError( 'mode must be baseline or increment' );
		}
		$source = strtolower( (string)( $this->getOption( 'source' ) ?: 'legi' ) );
		$parser = $this->parserFor( $source );
		$isLegi = $source === 'legi';
		$codesOnly = $isLegi && !$this->getOption( 'all-legi' );
		$inForceOnly = $isLegi && !$this->getOption( 'include-historical' );
		$createItems = $isLegi;
		if ( $this->getOption( 'create-items' ) ) {
			$createItems = true;
		}
		if ( $this->getOption( 'no-create-items' ) ) {
			$createItems = false;
		}
		$limit = (int)( $this->getOption( 'limit' ) ?: 0 );
		$dryRun = (bool)$this->getOption( 'dry-run' );

		$user = null;
		if ( $createItems && !$dryRun ) {
			$user = $this->resolveUser();
		}

		$services = MediaWikiServices::getInstance();
		$store = $services->get( 'EmbeddableContent.LawStore' );
		$writer = $services->get( 'EmbeddableContent.LawItemWriter' );
		$importer = new LawImporter( $store, $parser, $writer );
		$log = function ( string $message ): void {
			$this->output( $message . "\n" );
		};

		try {
			$stats = $importer->run( $dir, $mode, [
				'source' => $source,
				'codes_only' => $codesOnly,
				'in_force_only' => $inForceOnly,
				'limit' => $limit,
				'create_items' => $createItems,
				'dry_run' => $dryRun,
			], $user, $log );
			if ( $this->getOption( 'prune' ) && !$dryRun && $isLegi ) {
				$deleted = $store->pruneNonInForce( 'legi' );
				$this->output( "pruned non-in-force LEGI rows: $deleted\n" );
			}
		} catch ( LawImportException $e ) {
			$this->fatalError( $e->getMessage() );
		}
		$this->output( 'stats: ' . json_encode( $stats, JSON_UNESCAPED_UNICODE ) . "\n" );
	}

	private function parserFor( string $source ): LawSourceParser {
		return match ( $source ) {
			'legi' => new LegiParser(),
			'constit' => new ConstitParser(),
			'cass' => new CassParser(),
			'jade' => new JadeParser(),
			default => $this->fatalError( "unknown source: $source (legi|constit|cass|jade)" ),
		};
	}

	private function resolveUser(): User {
		$name = trim( (string)$this->getOption( 'user' ) );
		if ( $name !== '' ) {
			$user = User::newFromName( $name );
			if ( $user === null || !$user->isRegistered() ) {
				$this->fatalError( "unknown user: $name" );
			}
			return $user;
		}
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		if ( $user === null ) {
			$this->fatalError( 'cannot resolve the Maintenance script user' );
		}
		return $user;
	}
}

$maintClass = ImportLawCorpus::class;
require_once RUN_MAINTENANCE_IF_MAIN;
