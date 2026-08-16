<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\Content\ContentRenderer;
use EmbeddableContent\Content\RenderException;
use MediaWiki\Api\ApiBase;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Repo\WikibaseRepo;

/**
 * api.php?action=embed&entity=Q1&format=html|json&lang=… — same renderer as
 * Special:Embed, CORS `*` for cross-site consumers (issue #6 §4.3).
 *
 * @license GPL-2.0-or-later
 */
class ApiEmbed extends ApiBase {

	/** @var ContentRenderer */
	private $renderer;

	public function __construct( $mainModule, $moduleName, ContentRenderer $renderer ) {
		parent::__construct( $mainModule, $moduleName );
		$this->renderer = $renderer;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$format = $params['format'];
		$lang = $params['lang'] !== null ? $params['lang'] : null;

		$id = $this->parseItemId( $params['entity'] );
		if ( $id === null ) {
			$this->dieWithError( [ 'embeddablecontent-error-invalidentity' ], 'invalidentity' );
		}

		$acceptLanguages = array_keys( $this->getMain()->getRequest()->getAcceptLang() );

		try {
			$result = $this->renderer->render( $id, $format, $lang, null, $acceptLanguages );
		} catch ( RenderException $e ) {
			$this->dieWithError( [ 'embeddablecontent-error-' . $e->getErrorCode() ], $e->getErrorCode() );
		}

		$payload = [
			'kind' => $result->getKind(),
			'title' => $result->getTitle(),
			'lang' => $result->getLang(),
			'html' => $result->getHtml(),
		];
		if ( $format === 'json' ) {
			$payload['languages'] = $result->getLanguages();
		}

		// Cross-site consumers (issue #6 §4.3: CORS *).
		$this->getMain()->getRequest()->response()->header( 'Access-Control-Allow-Origin: *' );

		$this->getResult()->addValue( null, 'embed', $payload );
	}

	public function getAllowedParams() {
		return [
			'entity' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
			],
			'format' => [
				self::PARAM_TYPE => [ 'html', 'json' ],
				self::PARAM_DFLT => 'html',
			],
			'lang' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => false,
			],
		];
	}

	public function isWriteMode() {
		return false;
	}

	public function mustBePosted() {
		return false;
	}

	public function needsToken() {
		return false;
	}

	public function getModuleDescription() {
		return 'Render an embeddable content item (quotation, code snippet, mathematical expression) as an HTML fragment.';
	}

	private function parseItemId( string $input ): ?ItemId {
		try {
			$entityId = WikibaseRepo::getEntityIdParser()->parse( $input );
			return $entityId instanceof ItemId ? $entityId : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
