<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- maintenance entry point

declare( strict_types = 1 );

use DataValues\MonolingualTextValue;
use DataValues\StringValue;
use EmbeddableContent\Content\PayloadCodec;
use EmbeddableContent\PropertyIdFactory;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Statement\StatementList;
use Wikibase\Repo\WikibaseRepo;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false || $IP === '' ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Rewrite a cited provision item's clause text from the (corrected) corpus.
 *
 *   php maintenance/run.php .../refreshLawProvisionItems.php [--dry-run]
 *
 * Needed once after the v1 version-policy repair: the four items cited
 * before the repair may hold an obsolete article version (Q2074's
 * `L111-1` was a 2013 MODIFIE text). Court decisions are skipped — their
 * item is the decision itself and carries no quotation payload.
 */
class RefreshLawProvisionItems extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Refresh cited provision items from the law corpus.' );
		$this->addOption( 'dry-run', 'Print the items without writing.' );
	}

	public function execute() {
		$dry = (bool)$this->getOption( 'dry-run' );
		$services = MediaWikiServices::getInstance();
		$store = $services->get( 'EmbeddableContent.LawStore' );
		$config = $services->get( 'EmbeddableContent.Config' );
		$quotationProperty = $config->payloadPropertyIds()['quotation'] ?? null;
		$urlProperty = $config->sourcePropertyIds()['url'] ?? null;
		if ( $quotationProperty === null ) {
			$this->fatalError( 'the quotation payload property is not configured' );
		}
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		if ( $user === null ) {
			$this->fatalError( 'cannot resolve the Maintenance script user' );
		}
		$updated = 0;
		foreach ( $store->listCitedArticles() as $row ) {
			if ( in_array( (string)$row['source'], [ 'constit', 'cass', 'jade' ], true ) ) {
				continue;
			}
			$article = $store->getArticle(
				(string)$row['law_key'],
				(string)$row['reference_code'],
				(string)$row['language']
			);
			if ( $article === null ) {
				$this->output( 'MISSING corpus row: ' . $row['law_key'] . '/' . $row['reference_code'] . "\n" );
				continue;
			}
			$itemId = (string)$row['item_id'];
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
			if ( !$item instanceof Item ) {
				$this->output( "SKIPPED $itemId: not an item\n" );
				continue;
			}
			$language = (string)( $article['language'] ?? 'fr' );
			$this->output( sprintf(
				"%s %s/%s: %d chars%s\n",
				$itemId,
				$row['law_key'],
				$row['reference_code'],
				mb_strlen( (string)$article['text'] ),
				$dry ? ' (dry-run)' : ''
			) );
			if ( $dry ) {
				continue;
			}
			$kept = [];
			foreach ( $item->getStatements() as $statement ) {
				$property = $statement->getMainSnak() instanceof PropertyValueSnak
					? $statement->getMainSnak()->getPropertyId()->getSerialization()
					: '';
				if ( $property === $quotationProperty || ( $urlProperty !== null && $property === $urlProperty ) ) {
					continue;
				}
				$kept[] = $statement;
			}
			$item->setStatements( new StatementList( ...$kept ) );
			$item->getStatements()->addStatement( new Statement( new PropertyValueSnak(
				PropertyIdFactory::create( $quotationProperty ),
				new MonolingualTextValue( $language, PayloadCodec::escape( (string)$article['text'] ) )
			) ) );
			$url = trim( (string)( $article['source_url'] ?? '' ) );
			if ( $urlProperty !== null && $url !== '' ) {
				$item->getStatements()->addStatement( new Statement( new PropertyValueSnak(
					PropertyIdFactory::create( $urlProperty ),
					new StringValue( $url )
				) ) );
			}
			WikibaseRepo::getEntityStore()->saveEntity(
				$item,
				'law corpus: refresh provision text',
				$user,
				EDIT_UPDATE
			);
			$updated++;
		}
		$this->output( "updated=$updated\n" );
	}
}

$maintClass = RefreshLawProvisionItems::class;
require_once RUN_MAINTENANCE_IF_MAIN;
