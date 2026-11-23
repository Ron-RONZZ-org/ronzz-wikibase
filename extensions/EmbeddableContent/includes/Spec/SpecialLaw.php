<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Flow\LawCiteService;
use EmbeddableContent\Law\LawCiteException;
use EmbeddableContent\Store\LawStore;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * Special:Law — the human view of the law corpus and the cite-or-create
 * entry point. Replaces Special:AddSource/law.
 *
 * Routes:
 *   Special:Law                    search + the law list
 *   Special:Law?q=...              search results
 *   Special:Law/<law_key>          the law's articles
 *   Special:Law/<law_key>/<ref>    one article; the Cite action
 *
 * Legal items have no Source: page; this page is the readable target of
 * their citation links.
 *
 * @license GPL-2.0-or-later
 */
class SpecialLaw extends SpecialPage {

	public function __construct() {
		parent::__construct( 'Law' );
	}

	public function getDescription() {
		return $this->msg( 'special-law' );
	}

	protected function getGroupName(): string {
		return 'wikibase';
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->getOutput()->addModuleStyles( [ 'mediawiki.special', 'ext.embeddableContent.law' ] );
		$this->getOutput()->addHTML( $this->searchForm( $this->getRequest()->getText( 'q', '' ) ) );

		$store = MediaWikiServices::getInstance()->get( 'EmbeddableContent.LawStore' );
		if ( !$store->schemaExists() ) {
			$this->getOutput()->addHTML( Html::warningBox(
				$this->msg( 'embeddablecontent-law-notinstalled' )->escaped()
			) );
			return;
		}

		$subPage = trim( (string)$subPage );
		if ( $subPage === '' ) {
			$this->renderSearch( $this->getRequest()->getText( 'q', '' ), $store );
			return;
		}
		[ $lawKey, $reference ] = array_pad( explode( '/', $subPage, 2 ), 2, '' );
		$lawKey = trim( $lawKey );
		$reference = trim( $reference );
		$law = $store->getLaw( $lawKey );
		if ( $law === null ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'embeddablecontent-law-notfound', $lawKey )->escaped() ) );
			return;
		}
		$this->getOutput()->setPageTitleMsg( $this->msg( 'embeddablecontent-law-pagetitle', $law['title'] ) );
		if ( $reference === '' ) {
			$this->renderLaw( $store, $law );
			return;
		}
		$this->renderArticle( $store, $law, $reference );
	}

	private function renderSearch( string $query, LawStore $store ): void {
		$query = trim( $query );
		if ( $query === '' ) {
			$this->renderLawList( $store );
			return;
		}
		$articles = $store->searchArticles( $query, null, 50 );
		$laws = $store->searchLaws( $query, null, 20 );
		if ( $laws !== [] ) {
			$this->getOutput()->addHTML( Html::element( 'h2', [], $this->msg( 'embeddablecontent-law-results-laws' )->text() ) );
			$rows = [];
			foreach ( $laws as $law ) {
				$rows[] = Html::rawElement( 'tr', [], Html::rawElement( 'td', [],
					$this->link( $law['law_key'], (string)$law['title'] ) )
					. Html::element( 'td', [], (string)( $law['nature'] ?? '' ) )
					. Html::element( 'td', [], (string)( $law['status'] ?? '' ) ) );
			}
			$this->getOutput()->addHTML( $this->table(
				[ 'law', 'nature', 'status' ],
				$rows
			) );
		}
		if ( $articles !== [] ) {
			$this->getOutput()->addHTML( Html::element( 'h2', [], $this->msg( 'embeddablecontent-law-results-articles' )->text() ) );
			$rows = [];
			foreach ( $articles as $article ) {
				$rows[] = Html::rawElement( 'tr', [], Html::rawElement( 'td', [],
					$this->link( (string)$article['law_key'] . '/' . (string)$article['reference_code'],
						( $article['law_title'] ?? '' ) . ' — ' . (string)$article['reference_code'] ) )
					. Html::element( 'td', [], (string)( $article['section_path'] ?? '' ) )
					. Html::element( 'td', [], (string)( $article['status'] ?? '' ) )
					. Html::element( 'td', [], (string)( $article['item_id'] ?? '' ) ) );
			}
			$this->getOutput()->addHTML( $this->table(
				[ 'article', 'section', 'status', 'item' ],
				$rows
			) );
		}
		if ( $articles === [] && $laws === [] ) {
			$this->getOutput()->addHTML( Html::element( 'p', [], $this->msg( 'embeddablecontent-law-noresults', $query )->text() ) );
		}
	}

	private function renderLawList( LawStore $store ): void {
		$laws = $store->listLaws( null, 200 );
		if ( $laws === [] ) {
			$this->getOutput()->addHTML( Html::element( 'p', [], $this->msg( 'embeddablecontent-law-empty' )->text() ) );
			return;
		}
		$this->getOutput()->addHTML( Html::element( 'h2', [], $this->msg( 'embeddablecontent-law-list' )->text() ) );
		$rows = [];
		foreach ( $laws as $law ) {
			$rows[] = Html::rawElement( 'tr', [], Html::rawElement( 'td', [],
				$this->link( (string)$law['law_key'], (string)$law['title'] ) )
				. Html::element( 'td', [], (string)( $law['nature'] ?? '' ) )
				. Html::element( 'td', [], (string)( $law['version_start'] ?? '' ) ) );
		}
		$this->getOutput()->addHTML( $this->table( [ 'law', 'nature', 'since' ], $rows ) );
	}

	/**
	 * @param array<string,mixed> $law
	 */
	private function renderLaw( LawStore $store, array $law ): void {
		$this->getOutput()->addHTML( $this->lawHeader( $law ) );
		$articles = $store->listArticles( (string)$law['law_key'], 500 );
		if ( $articles === [] ) {
			$this->getOutput()->addHTML( Html::element( 'p', [], $this->msg( 'embeddablecontent-law-noarticles' )->text() ) );
			return;
		}
		$rows = [];
		foreach ( $articles as $article ) {
			$rows[] = Html::rawElement( 'tr', [], Html::rawElement( 'td', [],
				$this->link( (string)$law['law_key'] . '/' . (string)$article['reference_code'],
					'Article ' . (string)$article['reference_code'] ) )
				. Html::element( 'td', [], (string)( $article['section_path'] ?? '' ) )
				. Html::element( 'td', [], (string)( $article['status'] ?? '' ) )
				. Html::element( 'td', [], (string)( $article['item_id'] ?? '' ) ) );
		}
		$this->getOutput()->addHTML( $this->table( [ 'article', 'section', 'status', 'item' ], $rows ) );
		if ( count( $articles ) >= 500 ) {
			$this->getOutput()->addHTML( Html::element( 'p', [], $this->msg( 'embeddablecontent-law-truncated' )->text() ) );
		}
	}

	/**
	 * @param array<string,mixed> $law
	 */
	private function renderArticle( LawStore $store, array $law, string $reference ): void {
		$article = $store->getArticle( (string)$law['law_key'], $reference );
		if ( $article === null ) {
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'embeddablecontent-law-articlenotfound', $reference )->escaped()
			) );
			return;
		}
		$this->getOutput()->addHTML( $this->lawHeader( $law ) );
		$this->getOutput()->addHTML( $this->articleHeader( $law, $article ) );

		$justCited = false;
		if ( $this->getRequest()->wasPosted() && $this->getRequest()->getVal( 'action' ) === 'cite' ) {
			$justCited = $this->handleCite( $law, $article );
		}

		$text = trim( (string)( $article['text'] ?? '' ) );
		$this->getOutput()->addHTML( Html::rawElement( 'div', [ 'class' => 'wb-law-article-text' ],
			nl2br( htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) ) ) );

		if ( $justCited ) {
			return;
		}
		$itemId = trim( (string)( $article['item_id'] ?? '' ) );
		if ( $itemId !== '' ) {
			$this->getOutput()->addHTML( $this->refBox( $itemId, false ) );
		} elseif ( $this->getUser()->isAllowed( 'edit' ) ) {
			$this->getOutput()->addHTML( $this->citeForm( $law, $article ) );
		} else {
			$this->getOutput()->addHTML( Html::warningBox(
				$this->msg( 'embeddablecontent-law-login-to-cite' )->escaped()
			) );
		}
	}

	/**
	 * @param array<string,mixed> $law
	 * @param array<string,mixed> $article
	 */
	private function handleCite( array $law, array $article ): bool {
		if ( !$this->getUser()->matchEditToken( $this->getRequest()->getVal( 'token', '' ) ) ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'embeddablecontent-law-badtoken' )->escaped() ) );
			return false;
		}
		if ( !$this->getUser()->isAllowed( 'edit' ) ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'permissionserrors' )->escaped() ) );
			return false;
		}
		try {
			$result = MediaWikiServices::getInstance()->get( 'EmbeddableContent.LawCiteService' )
				->cite( (string)$law['law_key'], (string)$article['reference_code'], $this->getUser() );
		} catch ( LawCiteException $e ) {
			$this->getOutput()->addHTML( Html::errorBox( htmlspecialchars(
				$e->getErrorCode() . ': ' . $e->getMessage()
			) ) );
			return false;
		}
		$this->getOutput()->addHTML( $this->refBox( $result['qid'], $result['created'] ) );
		return true;
	}

	/**
	 * @param array<string,mixed> $law
	 * @param array<string,mixed> $article
	 */
	private function citeForm( array $law, array $article ): string {
		$title = $this->getPageTitle( (string)$law['law_key'] . '/' . (string)$article['reference_code'] );
		return Html::rawElement( 'form', [
			'method' => 'post',
			'action' => $title->getLocalURL(),
			'class' => 'wb-law-cite',
		],
			Html::hidden( 'action', 'cite' )
			. Html::hidden( 'law', (string)$law['law_key'] )
			. Html::hidden( 'article', (string)$article['reference_code'] )
			. Html::hidden( 'token', $this->getUser()->getEditToken() )
			. Html::element( 'button', [ 'type' => 'submit' ],
				$this->msg( 'embeddablecontent-law-cite' )->text() )
		);
	}

	private function refBox( string $itemId, bool $created ): string {
		$ref = '<ref>{{#cite:' . $itemId . '}}</ref>';
		$itemTitle = Title::newFromText( 'Item:' . $itemId );
		$itemLink = Html::element( 'a', [
			'href' => $itemTitle !== null ? $itemTitle->getLocalURL() : '#',
		], $itemId );
		$body = Html::element( 'p', [], $created
			? $this->msg( 'embeddablecontent-law-created' )->text()
			: $this->msg( 'embeddablecontent-law-already' )->text() );
		$body .= Html::element( 'p', [], $itemLink );
		$body .= Html::element( 'pre', [ 'class' => 'wb-law-ref' ], $ref );
		return Html::rawElement( 'div', [ 'class' => 'successbox wb-law-refbox' ], $body );
	}

	/**
	 * @param array<string,mixed> $law
	 */
	private function lawHeader( array $law ): string {
		$rows = [];
		foreach ( [
			'embeddablecontent-law-officialid' => (string)( $law['law_key'] ?? '' ),
			'embeddablecontent-law-nature' => (string)( $law['nature'] ?? '' ),
			'embeddablecontent-law-status' => (string)( $law['status'] ?? '' ),
			'embeddablecontent-law-since' => (string)( $law['version_start'] ?? '' ),
		] as $message => $value ) {
			if ( $value !== '' ) {
				$rows[] = Html::rawElement( 'tr', [],
					Html::element( 'th', [], $this->msg( $message )->text() )
					. Html::element( 'td', [], $value ) );
			}
		}
		$url = (string)( $law['source_url'] ?? '' );
		if ( $url !== '' ) {
			$rows[] = Html::rawElement( 'tr', [],
				Html::element( 'th', [], $this->msg( 'embeddablecontent-law-officialtext' )->text() )
				. Html::rawElement( 'td', [], Html::element( 'a', [ 'href' => $url ], $url ) ) );
		}
		return Html::rawElement( 'div', [ 'class' => 'wb-law-header' ],
			Html::element( 'h2', [], (string)( $law['title'] ?? '' ) )
			. Html::rawElement( 'table', [ 'class' => 'wikitable wb-law-facts' ], implode( '', $rows ) )
		);
	}

	/**
	 * @param array<string,mixed> $law
	 * @param array<string,mixed> $article
	 */
	private function articleHeader( array $law, array $article ): string {
		$title = 'Article ' . (string)( $article['reference_code'] ?? '' );
		$facts = [];
		foreach ( [
			'embeddablecontent-law-section' => (string)( $article['section_path'] ?? '' ),
			'embeddablecontent-law-status' => (string)( $article['status'] ?? '' ),
			'embeddablecontent-law-since' => (string)( $article['version_start'] ?? '' ),
		] as $message => $value ) {
			if ( $value !== '' ) {
				$facts[] = Html::element( 'span', [ 'class' => 'wb-law-fact' ],
					$this->msg( $message )->text() . ': ' . $value );
			}
		}
		return Html::rawElement( 'div', [ 'class' => 'wb-law-article-header' ],
			Html::element( 'h3', [], $title )
			. Html::rawElement( 'p', [ 'class' => 'wb-law-article-facts' ], implode( ' · ', $facts ) )
		);
	}

	private function searchForm( string $query ): string {
		return Html::rawElement( 'form', [
			'method' => 'get',
			'action' => $this->getPageTitle()->getLocalURL(),
			'class' => 'wb-law-search',
		],
			Html::element( 'input', [
				'type' => 'search',
				'name' => 'q',
				'value' => $query,
				'placeholder' => $this->msg( 'embeddablecontent-law-search-placeholder' )->text(),
				'class' => 'wb-law-search-input',
			] )
			. Html::element( 'button', [ 'type' => 'submit' ],
				$this->msg( 'embeddablecontent-law-search' )->text() )
		);
	}

	/**
	 * @param string[] $headers
	 * @param string[] $rows
	 */
	private function table( array $headers, array $rows ): string {
		$head = '';
		foreach ( $headers as $header ) {
			$head .= Html::element( 'th', [], $this->msg( 'embeddablecontent-law-col-' . $header )->text() );
		}
		return Html::rawElement( 'table', [ 'class' => 'wikitable wb-law-table' ],
			Html::rawElement( 'thead', [], Html::rawElement( 'tr', [], $head ) )
			. Html::rawElement( 'tbody', [], implode( '', $rows ) )
		);
	}

	private function link( string $subPage, string $label ): string {
		return Html::element( 'a', [
			'href' => $this->getPageTitle( $subPage )->getLocalURL(),
		], $label );
	}
}
