<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Special:EntityIndex — the alphabetical index of the top-level pages in a
 * namespace, reached from the namespace name in a content page's H1
 * ("Beloved <span>in</span> [[Special:EntityIndex/2012|Source]]").
 *
 * URL: Special:EntityIndex/<namespace id|name>. The listing is queried live
 * from the replica DB on every load (Special pages are never parser-cached):
 *
 *  - top-level pages only — subpages (`/fr`, `/archivedText`, `/quotation`)
 *    and redirects are excluded;
 *  - alphabetical by page title, cursor-paginated with `from` (the core
 *    Special:AllPages shape), so inserts cannot shift a page across chunks;
 *  - `limit` is clamped to 10–200, default 100.
 *
 * Each row links to the page itself. No config, vocabulary or parser cache is
 * involved.
 *
 * @license GPL-2.0-or-later
 */
class SpecialEntityIndex extends SpecialPage {

	public function __construct() {
		parent::__construct( 'EntityIndex' );
	}

	/**
	 * MediaWiki 1.46's default description key is the lowercased page name
	 * (`entityindex`); our i18n uses the legacy `special-<name>` key. The
	 * override keeps the page listed on Special:SpecialPages (T360723 skips
	 * pages whose description message is disabled) and gives it a title —
	 * the same pattern as the other EmbeddableContent special pages.
	 *
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'special-entityindex' );
	}

	protected function getGroupName(): string {
		return 'wikibase';
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( [
			'mediawiki.special',
			'ext.embeddableContent.entityindex',
		] );

		$namespace = self::resolveNamespace( $this->getLanguage(), (string)$subPage );
		if ( $namespace === null ) {
			$this->getOutput()->addHTML(
				Html::errorBox(
					$this->msg( 'embeddablecontent-entityindex-badnamespace', (string)$subPage )->escaped()
				)
			);
			return;
		}

		$namespaceName = $this->getLanguage()->getNamespaces()[$namespace] ?? '';
		if ( $namespaceName === '' ) {
			$this->getOutput()->addHTML(
				Html::errorBox(
					$this->msg( 'embeddablecontent-entityindex-badnamespace', (string)$subPage )->escaped()
				)
			);
			return;
		}

		$this->getOutput()->setPageTitleMsg(
			$this->msg( 'embeddablecontent-entityindex-title', $namespaceName )
		);
		$this->getOutput()->addHTML(
			Html::rawElement( 'p', [],
				$this->msg( 'embeddablecontent-entityindex-intro', $namespaceName )->escaped()
			)
		);

		$limit = EntityIndexPaging::clampLimit( $this->getRequest()->getInt( 'limit', EntityIndexPaging::DEFAULT_LIMIT ) );
		$baseTitle = SpecialPage::getTitleFor( 'EntityIndex', (string)$namespace );
		$this->getOutput()->addHTML( $this->limitForm( $baseTitle, $limit ) );

		$fromKey = $this->fromKey( $namespace );
		$pages = $this->fetchChunk( $namespace, $fromKey, $limit );
		if ( $pages === [] ) {
			$this->getOutput()->addHTML(
				Html::rawElement( 'p', [], $this->msg( 'embeddablecontent-entityindex-empty' )->escaped() )
			);
			return;
		}

		$hasNext = count( $pages ) > $limit;
		$nextPage = $hasNext ? $pages[$limit] : null;
		if ( $hasNext ) {
			array_pop( $pages );
		}

		$this->getOutput()->addHTML( $this->listHtml( $namespace, $pages ) );
		$this->getOutput()->addHTML(
			$this->navHtml( $baseTitle, $namespace, $limit, $fromKey, $nextPage )
		);
	}

	/**
	 * Resolves the subpage to a namespace id: a numeric id, a canonical
	 * name, a localized name or an alias. Returns null for an unknown or
	 * non-content namespace.
	 *
	 * @param \MediaWiki\Language\Language $language
	 */
	public static function resolveNamespace( $language, string $subPage ): ?int {
		$subPage = trim( $subPage );
		if ( $subPage === '' ) {
			return null;
		}
		$namespaces = $language->getNamespaces();
		if ( preg_match( '/^-?\d+$/', $subPage ) === 1 ) {
			$id = (int)$subPage;
			return array_key_exists( $id, $namespaces ) && $id >= 0 && $id !== NS_SPECIAL ? $id : null;
		}
		$id = $language->getNsIndex( $subPage );
		if ( $id === false || $id < 0 || $id === NS_SPECIAL ) {
			return null;
		}
		return $id;
	}

	/**
	 * The `from` cursor as a DB key (title text without the namespace).
	 */
	private function fromKey( int $namespace ): string {
		$from = trim( $this->getRequest()->getText( 'from', '' ) );
		if ( $from === '' ) {
			return '';
		}
		$title = Title::makeTitleSafe( $namespace, $from );
		return $title !== null ? $title->getDBkey() : '';
	}

	/**
	 * One chunk of top-level, non-redirect pages, ordered by title.
	 *
	 * @return \MediaWiki\Page\ExistingPageRecord[]
	 */
	private function fetchChunk( int $namespace, string $fromKey, int $limit ): array {
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$conds = [
			'page_namespace' => $namespace,
			'page_is_redirect' => 0,
			// Top-level pages only: `/` makes a title a subpage in these
			// subpage-enabled content namespaces (translations, archives,
			// quotation listings).
			$dbr->expr( 'page_title', IExpression::NOT_LIKE, new LikeValue( $dbr->anyString(), '/', $dbr->anyString() ) ),
		];
		if ( $fromKey !== '' ) {
			$conds[] = $dbr->expr( 'page_title', '>=', $fromKey );
		}

		return iterator_to_array(
			MediaWikiServices::getInstance()->getPageStore()
				->newSelectQueryBuilder()
				->where( $conds )
				->orderBy( 'page_title' )
				->limit( $limit + 1 )
				->caller( __METHOD__ )
				->fetchPageRecords()
		);
	}

	/**
	 * The first DB key of the chunk before $fromKey (the core AllPages
	 * previous-page math), or '' when there is no earlier chunk.
	 */
	private function previousKey( int $namespace, string $fromKey, int $limit ): string {
		if ( $fromKey === '' ) {
			return '';
		}
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$conds = [
			'page_namespace' => $namespace,
			'page_is_redirect' => 0,
			$dbr->expr( 'page_title', IExpression::NOT_LIKE, new LikeValue( $dbr->anyString(), '/', $dbr->anyString() ) ),
			$dbr->expr( 'page_title', '<', $fromKey ),
		];
		$record = $this->previousPageAt( $conds, $limit - 1 );
		if ( $record === null ) {
			$record = $this->previousPageAt( $conds, 0 );
		}
		return $record !== null ? $record->getDBkey() : '';
	}

	/**
	 * The page at one descending offset in the previous-page query.
	 *
	 * @param array $conds
	 * @return \MediaWiki\Page\ExistingPageRecord|null
	 */
	private function previousPageAt( array $conds, int $offset ) {
		return MediaWikiServices::getInstance()->getPageStore()
			->newSelectQueryBuilder()
			->where( $conds )
			->orderBy( 'page_title', SelectQueryBuilder::SORT_DESC )
			->limit( 1 )
			->offset( $offset )
			->caller( __METHOD__ )
			->fetchPageRecord();
	}

	/**
	 * @param \MediaWiki\Page\ExistingPageRecord[] $pages
	 */
	private function listHtml( int $namespace, array $pages ): string {
		$items = [];
		foreach ( $pages as $page ) {
			$title = Title::makeTitle( $namespace, $page->getDBkey() );
			$items[] = Html::rawElement( 'li', [], $this->getLinkRenderer()->makeKnownLink( $title ) );
		}
		return Html::rawElement(
			'div',
			[ 'class' => 'mw-allpages-body' ],
			Html::rawElement( 'ul', [ 'class' => 'mw-allpages-chunk' ], implode( "\n", $items ) )
		);
	}

	/**
	 * @param \MediaWiki\Page\ExistingPageRecord|null $nextPage
	 */
	private function navHtml(
		Title $baseTitle,
		int $namespace,
		int $limit,
		string $fromKey,
		$nextPage
	): string {
		$links = [];
		$prevKey = $this->previousKey( $namespace, $fromKey, $limit );
		if ( $prevKey !== '' ) {
			$prev = Title::makeTitle( $namespace, $prevKey );
			$links[] = $this->getLinkRenderer()->makeKnownLink(
				$baseTitle,
				$this->msg( 'prevpage', $prev->getText() )->text(),
				[],
				$this->query( $prev->getText(), $limit )
			);
		}
		if ( $nextPage !== null ) {
			$next = Title::makeTitle( $namespace, $nextPage->getDBkey() );
			$links[] = $this->getLinkRenderer()->makeKnownLink(
				$baseTitle,
				$this->msg( 'nextpage', $next->getText() )->text(),
				[],
				$this->query( $next->getText(), $limit )
			);
		}
		if ( $links === [] ) {
			return '';
		}
		return Html::rawElement(
			'div',
			[ 'class' => 'mw-allpages-nav' ],
			implode( ' | ', $links )
		);
	}

	/**
	 * @return array<string,string|int>
	 */
	private function query( string $from, int $limit ): array {
		$query = [ 'from' => $from ];
		if ( $limit !== EntityIndexPaging::DEFAULT_LIMIT ) {
			$query['limit'] = $limit;
		}
		return $query;
	}

	private function limitForm( Title $baseTitle, int $limit ): string {
		return Html::rawElement(
			'form',
			[
				'method' => 'get',
				'action' => $baseTitle->getLocalURL(),
				'class' => 'wb-entityindex-limit',
			],
			Html::label(
				$this->msg( 'embeddablecontent-entityindex-limit' )->escaped(),
				'wb-entityindex-limit'
			)
			. ' '
			. Html::input( 'limit', (string)$limit, 'number', [
				'id' => 'wb-entityindex-limit',
				'min' => (string)EntityIndexPaging::MIN_LIMIT,
				'max' => (string)EntityIndexPaging::MAX_LIMIT,
			] )
			. ' '
			. Html::element( 'button', [ 'type' => 'submit' ], $this->msg( 'embeddablecontent-entityindex-limit-apply' )->text() )
		);
	}
}
