<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- maintenance entry point

declare( strict_types = 1 );

use EmbeddableContent\PropertyIdFactory;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\Repo\WikibaseRepo;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false || $IP === '' ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Create the court items the law-corpus case law statements point at.
 *
 *   php maintenance/run.php .../createLawCourtItems.php [--class=Q…]
 *
 * The `court` property is item-valued, so the corpus writer resolves the
 * court by its exact label. The class (`court`, Wikidata Q41487) comes from
 * the seed vocabulary; pass --class when the term search cannot find it.
 * Idempotent: an existing exact-label item is reused.
 */
class CreateLawCourtItems extends Maintenance {

	private const COURTS = [
		'Conseil constitutionnel' => 'juridiction constitutionnelle française',
		'Cour de cassation' => 'juridiction judiciaire suprême française',
		'Tribunal des conflits' => 'juridiction française des conflits de compétence',
		'Conseil d\'État' => 'juridiction administrative suprême française',
	];

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Create the court items for the law-corpus case law.' );
		$this->addOption( 'class', 'The court class item id (default: resolve by label).', false, true );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$termSearch = $services->get( 'EmbeddableContent.EntityTermSearch' );
		$classId = trim( (string)$this->getOption( 'class' ) );
		if ( $classId === '' ) {
			foreach ( [ [ 'juridiction', 'fr' ], [ 'court', 'en' ], [ 'tribunalo', 'eo' ] ] as [ $label, $lang ] ) {
				foreach ( $termSearch->search( $label, $lang, 10, [] ) as $entry ) {
					if ( mb_strtolower( trim( (string)( $entry['label'] ?? '' ) ) ) === mb_strtolower( $label ) ) {
						$classId = (string)$entry['id'];
						break 2;
					}
				}
			}
		}
		if ( $classId === '' ) {
			$this->fatalError( 'court class not found — run the seed (properties/classes) or pass --class' );
		}
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		if ( $user === null ) {
			$this->fatalError( 'cannot resolve the Maintenance script user' );
		}
		$instanceOf = $services->get( 'EmbeddableContent.Config' )->instanceOfPropertyId();
		foreach ( self::COURTS as $label => $description ) {
			$existing = $this->findByLabel( $termSearch, $label );
			if ( $existing !== null ) {
				$this->output( "$label -> $existing (existing)\n" );
				continue;
			}
			$item = new Item();
			$item->setLabel( 'fr', $label );
			$item->setDescription( 'fr', $description );
			$item->setDescription( 'en', 'French court' );
			$item->getStatements()->addStatement( new Statement(
				new PropertyValueSnak(
					PropertyIdFactory::create( $instanceOf ),
					new EntityIdValue( new ItemId( $classId ) )
				)
			) );
			try {
				$revision = WikibaseRepo::getEntityStore()->saveEntity(
					$item,
					'law corpus: create court item',
					$user,
					EDIT_NEW
				);
			} catch ( \Throwable $e ) {
				$this->error( "FAILED $label: " . $e->getMessage() );
				continue;
			}
			$id = $revision->getEntity()->getId();
			$this->output( "$label -> " . ( $id !== null ? $id->getSerialization() : '?' ) . "\n" );
		}
	}

	private function findByLabel( $termSearch, string $label ): ?string {
		foreach ( $termSearch->search( $label, 'fr', 10, [] ) as $entry ) {
			if ( mb_strtolower( trim( (string)( $entry['label'] ?? '' ) ) ) === mb_strtolower( $label ) ) {
				return (string)$entry['id'];
			}
		}
		return null;
	}
}

$maintClass = CreateLawCourtItems::class;
require_once RUN_MAINTENANCE_IF_MAIN;
