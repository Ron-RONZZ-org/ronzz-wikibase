<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- entry point name

declare( strict_types = 1 );

namespace EmbeddableContent\Maintenance;

use EmbeddableContent\Flow\StatementGuidAssigner;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\User\User;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\Repo\Store\Sql\SqlEntityIdPagerFactory;
use Wikibase\Repo\WikibaseRepo;

/**
 * Backfills statement GUIDs on items whose statements were created without
 * them — every item created through the flow services before the 2026-08-31
 * fix (browser Add* forms and the entity-mode API modules built statements
 * via addNewStatement() without a GUID; the entity-page client matches
 * statements to the DOM BY GUID, so a GUID-less statement renders as an
 * empty edit-mode row for logged-in users).
 *
 * Idempotent: items whose statements all carry GUIDs are untouched; the
 * existing GUIDs are never rewritten (StatementGuidAssigner contract).
 *
 * Options:
 *   --dry-run        list the affected items, change nothing
 *   --verify         scan again after a run; exit 1 when any item still has
 *                    GUID-less statements (the deploy gate)
 *   --batch-size=N   entity-id page size (default 250)
 *   --user=NAME      save as this user (default: the maintenance system user)
 *
 * Usage: sudo -u ronzz php maintenance/run.php \
 *            extensions/EmbeddableContent/maintenance/assignStatementGuids.php --dry-run
 *
 * @license GPL-2.0-or-later
 */
class AssignStatementGuids extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Assigns GUIDs to statements that lack them (the flow-services GUID bug).' );

		$this->addOption( 'dry-run', 'List the affected items without saving.' );
		$this->addOption( 'verify', 'Scan and exit 1 when GUID-less statements remain.' );
		$this->addOption( 'batch-size', 'Entity-id page size', false, true );
		$this->addOption( 'user', 'User to save as (default: maintenance system user)', false, true );
	}

	public function execute() {
		$dryRun = $this->hasOption( 'dry-run' );
		$verify = $this->hasOption( 'verify' );
		$batchSize = max( 1, (int)$this->getOption( 'batch-size', 250 ) );

		$pager = ( new SqlEntityIdPagerFactory(
			WikibaseRepo::getEntityNamespaceLookup(),
			WikibaseRepo::getEntityIdLookup(),
			WikibaseRepo::getRepoDomainDbFactory()->newRepoDb()
		) )->newSqlEntityIdPager( [ Item::ENTITY_TYPE ] );

		$lookup = WikibaseRepo::getEntityLookup();
		$store = WikibaseRepo::getEntityStore();
		$guidGenerator = new GuidGenerator();
		$user = $this->maintUser();
		$summary = 'Backfilling statement GUIDs (flow-services GUID bug fix)';

		$scanned = 0;
		$affected = 0;
		$fixed = 0;

		while ( $ids = $pager->fetchIds( $batchSize ) ) {
			foreach ( $ids as $id ) {
				if ( !$id instanceof EntityId ) {
					continue;
				}
				$scanned++;
				$item = $lookup->getEntity( $id );
				if ( !$item instanceof Item ) {
					continue;
				}
				if ( !self::hasGuidLessStatement( $item ) ) {
					continue;
				}
				$affected++;
				$this->output( $dryRun || $verify ? "[affected] {$id->getSerialization()}\n" : '' );
				if ( $dryRun || $verify ) {
					continue;
				}
				StatementGuidAssigner::ensureGuids( $item, $guidGenerator );
				$store->saveEntity( $item, $summary, $user, EDIT_UPDATE );
				$fixed++;
			}
		}

		if ( $verify ) {
			if ( $affected > 0 ) {
				$this->error( "VERIFY FAILED: {$affected} item(s) still have GUID-less statements.\n" );
				$this->fatalError( 'GUID-less statements remain — the backfill did not fully apply.' );
			}
			$this->output( "Verify OK: no GUID-less statements remain ({$scanned} items scanned).\n" );
			return;
		}

		if ( $dryRun ) {
			$this->output( "Dry run: {$affected} item(s) would be fixed ({$scanned} scanned).\n" );
			return;
		}

		$this->output( "Fixed {$fixed} item(s) ({$scanned} scanned, {$affected} affected).\n" );
		if ( $fixed !== $affected ) {
			$this->error( 'Affected/fixed mismatch — re-run with --verify before deploying.\n' );
		}
	}

	/** @return bool */
	private static function hasGuidLessStatement( Item $item ): bool {
		foreach ( $item->getStatements() as $statement ) {
			if ( $statement->getGuid() === null ) {
				return true;
			}
		}
		return false;
	}

	private function maintUser(): User {
		$userName = $this->getOption( 'user' );
		if ( $userName !== null ) {
			$user = User::newFromName( $userName );
			if ( $user === null || $user->getId() === 0 ) {
				$this->fatalError( "Unknown user \"$userName\"" );
			}
			return $user;
		}
		return User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] );
	}

}

return AssignStatementGuids::class;
