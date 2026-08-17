<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Content\ContentRenderer;
use EmbeddableContent\Content\RenderException;
use MediaWiki\SpecialPage\SpecialPage;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Special:Embed — renders the embeddable fragment for a content item.
 *
 * Surfaces (issue #6 §4.3):
 * - `/embed/QN` — nginx rewrite to this page, article-body-only output
 * - `?lang=`, `?rev=`, `?format=json`, `?frame=1`
 * - `/embed/oembed?url=…` — oEmbed responder (JSON)
 *
 * @license GPL-2.0-or-later
 */
class SpecialEmbed extends SpecialPage {

	/** @var ContentRenderer */
	private $renderer;

	public function __construct( ContentRenderer $renderer ) {
		parent::__construct( 'Embed' );
		$this->renderer = $renderer;
	}

	public function execute( $subPage ) {
		// Standard special-page header plumbing (title from getDescription(),
		// noindex + article-related=false); handlers may override the title.
		$this->setHeaders();
		$request = $this->getRequest();
		$output = $this->getOutput();

		if ( trim( (string)$subPage ) === 'oembed' ) {
			$this->respondOEmbed();
			return;
		}

		$id = $this->parseEntityId( trim( (string)$subPage ) );
		if ( $id === null ) {
			$this->showErrorPage( 'embeddablecontent-error-title', 'embeddablecontent-error-invalidentity' );
			return;
		}

		$format = $request->getRawVal( 'format' ) ?? 'html';
		$lang = $request->getRawVal( 'lang' );
		$revId = $request->getIntOrNull( 'rev' );
		$acceptLanguages = array_keys( $request->getAcceptLang() );

		try {
			$result = $this->renderer->render( $id, $format, $lang, $revId, $acceptLanguages );
		} catch ( RenderException $e ) {
			$this->respondError( $e );
			return;
		}

		// Headers are set per path: json/oEmbed/error responses use
		// articleBodyOnly internally; the html path uses the embed skin.
		$response = $request->response();
		$response->header( 'ETag: ' . $result->getEtag() );
		if ( $result->getLastModified() !== null ) {
			$response->header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $result->getLastModified() ) . ' GMT' );
		}
		$response->header( 'Cache-Control: public, max-age=300' );

		if ( $request->getHeader( 'If-None-Match' ) === $result->getEtag() ) {
			$response->statusHeader( 304 );
			return;
		}

		if ( $format === 'json' ) {
			$this->respondJson(
				[
					'embed' => [
						'kind' => $result->getKind(),
						'title' => $result->getTitle(),
						'lang' => $result->getLang(),
						'html' => $result->getHtml(),
						'languages' => $result->getLanguages(),
					],
				],
				200
			);
			return;
		}

		// HTML output: render with the minimal "embed" skin so the page has
		// a <head> (ResourceLoader modules — KaTeX, highlight.js, embed CSS)
		// but no wiki chrome. The bare fragment alone would have no head at
		// all (articleBodyOnly), so client-side rendering could never run.
		$output->setArticleBodyOnly( false );
		$output->addModules( 'ext.embeddableContent.embed' );
		try {
			$skin = \MediaWiki\MediaWikiServices::getInstance()->getSkinFactory()->makeSkin( 'embedskin' );
			$this->getContext()->setSkin( $skin );
		} catch ( \Throwable $e ) {
			// Skin unavailable — the default skin still renders the head.
		}

		if ( $this->wantsFramedPage( $request ) ) {
			// Explicit ?frame=1 only: title + view-entity link + fragment.
			// The fragment is added exactly once.
			$this->renderFramedPage( $result, $id );
		} else {
			// Default: the bare fragment, no wiki chrome — this is what an
			// <iframe> embed on a third-party site should show.
			$output->addHTML( $result->getHtml() );
		}
	}

	/**
	 * oEmbed responder: /embed/oembed?url=https://wikibase.ronzz.org/wiki/Item:Q1
	 * (or /entity/Q1, /embed/Q1). Returns { type: "rich", html, title }.
	 */
	private function respondOEmbed(): void {
		$request = $this->getRequest();
		$output = $this->getOutput();
		$url = $request->getRawVal( 'url' ) ?? '';

		$id = $this->extractIdFromUrl( $url );
		if ( $id === null ) {
			$this->respondJson( [ 'error' => 'invalid url' ], 400 );
			return;
		}

		try {
			$result = $this->renderer->render( $id, 'html', null, null, array_keys( $request->getAcceptLang() ) );
		} catch ( RenderException $e ) {
			$this->respondJson( [ 'error' => $e->getErrorCode() ], $e->getHttpStatus() );
			return;
		}

		$this->respondJson(
			[
				'type' => 'rich',
				'version' => '1.0',
				'title' => $result->getTitle(),
				'html' => $result->getHtml(),
				'width' => 640,
				'height' => 320,
			],
			200
		);
	}

	private function respondJson( array $payload, int $status ): void {
		$output = $this->getOutput();
		$output->setArticleBodyOnly( true );
		$output->disable(); // prevent OutputPage from overriding the Content-Type
		$response = $this->getRequest()->response();
		$response->statusHeader( $status );
		$response->header( 'Content-Type: application/json; charset=utf-8' );
		echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function showErrorPage( string $titleKey, string $messageKey ): void {
		$output = $this->getOutput();
		$output->setPageTitle( $this->msg( $titleKey )->text() );
		$output->addHTML(
			\MediaWiki\Html\Html::errorBox( $this->msg( $messageKey )->escaped() )
		);
	}

	private function respondError( RenderException $e ): void {
		$output = $this->getOutput();
		$output->setArticleBodyOnly( true );
		$response = $this->getRequest()->response();
		$response->header( 'Content-Type: text/plain; charset=utf-8' );
		$response->statusHeader( $e->getHttpStatus() );
		$output->addHTML( $e->getErrorCode() . ': ' . $e->getMessage() . "\n" );
	}

	private function renderFramedPage( $result, ItemId $id ): void {
		$output = $this->getOutput();
		$output->setPageTitle( $result->getTitle() );
		$output->addModuleStyles( 'ext.embeddableContent.embed' );
		$title = WikibaseRepo::getEntityTitleStoreLookup()->getTitleForId( $id );
		$entityUrl = $title !== null ? $title->getFullURL() : '#';
		$output->addHTML(
			'<div class="wb-embed-frame">'
			. $result->getHtml()
			. '<p class="wb-embed-frame-actions"><a href="' . htmlspecialchars( $entityUrl, ENT_QUOTES, 'UTF-8' ) . '">'
			. wfMessage( 'embeddablecontent-frame-viewentity' )->escaped()
			. '</a></p></div>'
		);
	}

	private function wantsFramedPage( $request ): bool {
		// Framing only on explicit ?frame=1 — NOT on Sec-Fetch-Mode: navigate
		// (that header is also sent by <iframe> embeds, which would wrap the
		// bare fragment in the full wiki skin; people want the quote, not the
		// page chrome).
		return $request->getRawVal( 'frame' ) === '1';
	}

	private function parseEntityId( string $input ): ?ItemId {
		if ( $input === '' ) {
			return null;
		}
		try {
			$entityId = WikibaseRepo::getEntityIdParser()->parse( $input );
			return $entityId instanceof ItemId ? $entityId : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function extractIdFromUrl( string $url ): ?ItemId {
		if ( preg_match( '#/(?:entity|embed|wiki/Item:)(Q[1-9][0-9]*)(?:[?/].*)?$#', $url, $m ) === 1 ) {
			return $this->parseEntityId( $m[1] );
		}
		return null;
	}

	/**
	 * MW 1.43+ resolves the special-page description from the bare lowercase
	 * page name (strtolower( $this->mName )); our i18n uses the legacy
	 * `special-<name>` keys. Override to keep the page listed on
	 * Special:SpecialPages (T360723 skips pages whose description message
	 * is disabled) and to render a proper page title — same pattern as
	 * Wikibase's SpecialWikibasePage.
	 */
	public function getDescription() {
		return $this->msg( 'special-' . strtolower( $this->getName() ) );
	}

	protected function getGroupName(): string {
		return 'other';
	}
}
