<?php

declare( strict_types = 1 );

namespace WikibaseCitation\Api;

use MediaWiki\Api\ApiBase;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikibase\Repo\WikibaseRepo;
use WikibaseCitation\CitationFormatter;
use WikibaseCitation\StatementToCslConverter;
use Wikimedia\ObjectCache\BagOStuff;

/**
 * api.php?action=citation&entity=Q1&style=json|apa|vancouver|bibtex|ris&format=html|text
 * (issue #6 §7). EntityLookup -> statements -> CSL-JSON (via the admin-editable
 * citation maps) -> formatting. revId-keyed cache like embeds; missing fields
 * are omitted, never fatal.
 *
 * @license GPL-2.0-or-later
 */
class ApiCitation extends ApiBase {

	private const CACHE_TTL = 300;

	/** @var StatementToCslConverter */
	private $converter;

	/** @var CitationFormatter */
	private $formatter;

	/** @var EntityLookup */
	private $entityLookup;

	/** @var EntityRevisionLookup */
	private $revisionLookup;

	/** @var BagOStuff */
	private $cache;

	public function __construct(
		$mainModule,
		$moduleName,
		StatementToCslConverter $converter,
		CitationFormatter $formatter,
		EntityLookup $entityLookup,
		EntityRevisionLookup $revisionLookup,
		BagOStuff $cache
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->converter = $converter;
		$this->formatter = $formatter;
		$this->entityLookup = $entityLookup;
		$this->revisionLookup = $revisionLookup;
		$this->cache = $cache;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$style = $params['style'];
		$format = $params['output'];

		$id = $this->parseItemId( $params['entity'] );
		if ( $id === null ) {
			$this->dieWithError( [ 'wikibasecitation-error-invalidentity' ], 'invalidentity' );
		}

		$revision = $this->revisionLookup->getEntityRevision( $id );
		$item = $revision !== null ? $revision->getEntity() : $this->entityLookup->getEntity( $id );
		if ( !$item instanceof Item ) {
			$this->dieWithError( [ 'wikibasecitation-error-notfound' ], 'entitynotfound' );
		}

		$revId = $revision !== null ? $revision->getRevisionId() : 0;
		$cacheKey = $this->cache->makeKey(
			'WikibaseCitation', 'citation', $id->getSerialization(), (string)$revId, $style, $format
		);

		$cached = $this->cache->get( $cacheKey );
		if ( is_string( $cached ) ) {
			$citationText = $cached;
		} else {
			$csl = $this->converter->toCslJson( $item );
			$citationText = $style === 'json' ? $csl : $this->formatter->format( $csl, $style, $format );
			if ( $style !== 'json' ) {
				$this->cache->set( $cacheKey, $citationText, self::CACHE_TTL );
			}
		}

		$this->getMain()->getRequest()->response()->header( 'Access-Control-Allow-Origin: *' );

		$result = $this->getResult();
		$result->addValue( null, 'entity', $id->getSerialization() );
		$result->addValue( null, 'style', $style );
		$result->addValue( null, 'citation', $citationText );
	}

	public function getAllowedParams() {
		return [
			'entity' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
			],
			'style' => [
				self::PARAM_TYPE => CitationFormatter::STYLES,
				self::PARAM_DFLT => 'json',
			],
			'output' => [
				self::PARAM_TYPE => [ 'html', 'text' ],
				self::PARAM_DFLT => 'text',
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
		return 'Cite a Wikibase content item in json, APA, Vancouver, BibTeX or RIS format.';
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
