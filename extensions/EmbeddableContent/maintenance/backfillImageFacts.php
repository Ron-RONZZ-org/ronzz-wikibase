<?php
// phpcs:disable MediaWiki.Files.ClassMatchesFilename.WrongCase -- entry point name

declare( strict_types = 1 );

namespace EmbeddableContent\Maintenance;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Content\TextContent;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Replaces the upload-time static Licensing/Attribution blocks on File:
 * pages with `{{#image-facts:}}`, so the displayed facts derive from the
 * sitelinked image item (semantic-first) instead of frozen upload text.
 *
 * Safety: only a page whose generated block is at the END of the wikitext
 * is rewritten; a page with hand-written content after the block, or a page
 * that already uses the parser function, is skipped. Pages without an image
 * item (or without any facts) are skipped.
 *
 * Options:
 *   --dry-run   list what would change (default when --apply is absent)
 *   --apply     write the page edits
 *   --verify    after a run, exit 1 when an eligible page still lacks
 *               {{#image-facts:}}
 *   --limit=N   stop after N eligible pages
 *   --user=NAME save as this user (default: maintenance system user)
 *
 * Usage: sudo -u www-data php maintenance/run.php \
 *            extensions/EmbeddableContent/maintenance/backfillImageFacts.php --dry-run
 *
 * @license GPL-2.0-or-later
 */
class BackfillImageFacts extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Rewrite File: page Licensing/Attribution blocks to {{#image-facts:}}.' );
		$this->addOption( 'dry-run', 'List the pages without saving (default).' );
		$this->addOption( 'apply', 'Write the page edits.' );
		$this->addOption( 'verify', 'Exit 1 when an eligible page still lacks {{#image-facts:}}.' );
		$this->addOption( 'limit', 'Maximum pages to process', false, true );
		$this->addOption( 'user', 'User to save as (default: maintenance system user)', false, true );
	}

	public function execute() {
		$apply = $this->hasOption( 'apply' );
		$verify = $this->hasOption( 'verify' );
		$limit = (int)$this->getOption( 'limit', 0 );
		$config = $this->getServiceContainer()->get( 'EmbeddableContent.Config' );
		if ( !$config instanceof EmbeddableContentConfig ) {
			$this->fatalError( 'EmbeddableContent config service is not available.' );
		}
		$user = $this->maintUser();
		$summary = 'Semantic-first File page: render the image facts from the item';

		$licenseHeader = wfMessage( 'license-header' )->inContentLanguage()->text();
		if ( $licenseHeader === '' ) {
			$licenseHeader = 'Licensing';
		}
		$attrHeader = wfMessage( 'embeddablecontent-upload-attribution-header' )
			->inContentLanguage()->text();
		$patterns = [
			'/\n?==\s*' . preg_quote( $licenseHeader, '/' ) . '\s*==[\s\S]*?==\s*'
				. preg_quote( $attrHeader, '/' ) . '\s*==[\s\S]*$/u',
			'/\n?==\s*' . preg_quote( $attrHeader, '/' ) . '\s*==[\s\S]*$/u',
		];

		$dbr = $this->getServiceContainer()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$rows = $dbr->select(
			[ 'page', 'page_props' ],
			[ 'page_title', 'pp_value' ],
			[ 'page_namespace' => NS_FILE, 'pp_propname' => 'wikibase_item' ],
			__METHOD__,
			[ 'ORDER BY' => 'page_title' ],
			[ 'page_props' => [ 'JOIN', 'pp_page = page_id' ] ]
		);

		$eligible = 0;
		$changed = 0;
		foreach ( $rows as $row ) {
			if ( $limit > 0 && $eligible >= $limit ) {
				break;
			}
			$title = Title::makeTitle( NS_FILE, (string)$row->page_title );
			if ( $title === null ) {
				continue;
			}
			$item = $this->loadItem( (string)$row->pp_value );
			if ( $item === null || !$this->hasFacts( $config, $item ) ) {
				continue;
			}
			$wikiPage = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
			$content = $wikiPage->getContent();
			if ( !$content instanceof TextContent ) {
				continue;
			}
			$text = $content->getText();
			if ( str_contains( $text, '{{#image-facts:' ) ) {
				continue;
			}
			$new = $text;
			foreach ( $patterns as $pattern ) {
				$replaced = preg_replace( $pattern, '', $new, 1 );
				if ( is_string( $replaced ) && $replaced !== $new ) {
					$new = $replaced;
					break;
				}
			}
			if ( $new === $text ) {
				continue;
			}
			$eligible++;
			$new = rtrim( $new ) . "\n\n{{#image-facts:}}\n";
			$this->output( ( $apply ? 'REWRITE ' : 'WOULD REWRITE ' ) . $title->getPrefixedText() . "\n" );
			if ( !$apply ) {
				continue;
			}
			$status = $wikiPage->doUserEditContent(
				new WikitextContent( $new ),
				$user,
				$summary,
				EDIT_UPDATE
			);
			if ( !$status->isOK() ) {
				$this->error( '  failed: ' . $status->getMessage()->text() . "\n" );
				continue;
			}
			$changed++;
		}

		$this->output( "eligible: {$eligible}, changed: {$changed}\n" );
		if ( $verify ) {
			$missing = 0;
			foreach ( $rows as $row ) {
				$title = Title::makeTitle( NS_FILE, (string)$row->page_title );
				if ( $title === null ) {
					continue;
				}
				$wikiPage = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
				$content = $wikiPage->getContent();
				if ( !$content instanceof TextContent ) {
					continue;
				}
				$item = $this->loadItem( (string)$row->pp_value );
				if ( $item === null || !$this->hasFacts( $config, $item ) ) {
					continue;
				}
				if ( !str_contains( $content->getText(), '{{#image-facts:' ) ) {
					$missing++;
					$this->error( 'STILL STATIC: ' . $title->getPrefixedText() . "\n" );
				}
			}
			if ( $missing > 0 ) {
				$this->fatalError( "{$missing} eligible page(s) still lack {{#image-facts:}}." );
			}
			$this->output( "verify OK\n" );
		}
	}

	private function loadItem( string $itemId ): ?Item {
		if ( preg_match( '/^Q[1-9]\d*$/', $itemId ) !== 1 ) {
			return null;
		}
		try {
			$entity = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $itemId ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $entity instanceof Item ? $entity : null;
	}

	private function hasFacts( EmbeddableContentConfig $config, Item $item ): bool {
		$props = $config->imagePropertyIds();
		$provenance = $config->provenancePropertyIds();
		foreach ( [
			$props['license'] ?? null,
			$props['imageAuthor'] ?? null,
			$props['imageLicenseInfo'] ?? null,
			$props['imageSource'] ?? null,
			$provenance['attributedTo'] ?? null,
			$provenance['source'] ?? null,
			$provenance['sourceUrl'] ?? null,
		] as $propertyId ) {
			if ( $propertyId === null ) {
				continue;
			}
			if ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) )->count() > 0 ) {
				return true;
			}
		}
		return false;
	}

	private function maintUser(): User {
		$name = (string)$this->getOption( 'user', '' );
		if ( $name !== '' ) {
			return User::newFromName( $name ) ?? User::newSystemUser( 'Maintenance script', [ 'steal' ] );
		}
		return User::newSystemUser( 'Maintenance script', [ 'steal' ] );
	}
}
