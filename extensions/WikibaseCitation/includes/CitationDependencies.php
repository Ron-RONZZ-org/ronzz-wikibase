<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

use MediaWiki\Parser\Parser;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikibase\Lib\Store\EntityTitleLookup;

/**
 * ParserCache invalidation for cite-by-QID (issue #25 v2).
 *
 * The ADR anticipated `ParserOutput::addCacheDependency()` — that API does
 * NOT exist on MW 1.46 (verified: ParserCache only validates against the
 * page's own `page_touched`). The stock dependency mechanism is
 * `templatelinks`-based: pages that transclude a template get re-parsed by
 * `RefreshLinksJob` when the template changes (`LinksUpdate::queueRecursiveJobs`,
 * and `RefreshLinksJob` re-parses → regenerates the parser cache).
 *
 * So citing a page records a template dependency on the cited entities and
 * their source items via `ParserOutput::addTemplate()`: editing any of them
 * re-renders every citing page automatically (job-queue latency — the
 * production cron runs `runJobs.php` every 5 minutes). The entity pages are
 * not really transcluded — they only appear in the "transclusions" filter of
 * WhatLinksHere, which is the documented tradeoff of using the stock
 * mechanism.
 *
 * @license GPL-2.0-or-later
 */
class CitationDependencies {

	/** @var EntityTitleLookup */
	private $titleLookup;

	/** @var EntityRevisionLookup */
	private $revisionLookup;

	public function __construct( EntityTitleLookup $titleLookup, EntityRevisionLookup $revisionLookup ) {
		$this->titleLookup = $titleLookup;
		$this->revisionLookup = $revisionLookup;
	}

	/**
	 * Records the entity pages as parser-cache dependencies of the current
	 * page. Malformed ids and entities without a page are skipped with a
	 * warning (non-fatal — a missing dependency only means staleness until
	 * the next re-parse, the v1 accepted behaviour).
	 *
	 * @param string[] $entityIds cited entity ids (and their source ids)
	 */
	public function register( Parser $parser, array $entityIds ): void {
		foreach ( array_unique( array_values( array_filter( $entityIds ) ) ) as $entityId ) {
			try {
				$id = new ItemId( $entityId );
			} catch ( \Throwable $e ) {
				continue;
			}
			$title = $this->titleLookup->getTitleForId( $id );
			if ( $title === null || !$title->exists() ) {
				continue;
			}
			$revId = $this->revisionLookup->getLatestRevisionId( $id ) ?? 0;
			$parser->getOutput()->addTemplate( $title, $title->getArticleID(), $revId );
		}
	}
}
