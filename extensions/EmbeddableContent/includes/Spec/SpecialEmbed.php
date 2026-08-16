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

		$output->setArticleBodyOnly( true );
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
			$response->header( 'Content-Type: application/json; charset=utf-8' );
			$output->addHTML(
				json_encode(
					[
						'embed' => [
							'kind' => $result->getKind(),
							'title' => $result->getTitle(),
							'lang' => $result->getLang(),
							'html' => $result->getHtml(),
							'languages' => $result->getLanguages(),
						],
					],
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			);
			return;
		}

		$output->addHTML( $result->getHtml() );

		if ( $this->wantsFramedPage( $request ) ) {
			$output->setArticleBodyOnly( false );
			$this->renderFramedPage( $result, $id );
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
		$response = $this->getRequest()->response();
		$response->header( 'Content-Type: application/json; charset=utf-8' );
		$response->statusHeader( $status );
		$output->addHTML( json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private function respondError( RenderException $e ): void {
		$output = $this->getOutput();
		$output->setArticleBodyOnly( true );
		$response = $this->getRequest()->response();
		$response->header( 'Content-Type: text/plain; charset=utf-8' );
		$response->statusHeader( $e->getHttpStatus() );
		$output->addHTML( $e->getErrorCode() . "\n" );
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
		if ( $request->getRawVal( 'frame' ) === '1' ) {
			return true;
		}
		return $request->getHeader( 'Sec-Fetch-Mode' ) === 'navigate';
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

	protected function getGroupName(): string {
		return 'other';
	}
}
