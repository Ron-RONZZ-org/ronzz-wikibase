<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Store;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;

/**
 * The apply pipeline shared by the API/job: store rows → Wikibase items →
 * view page → verify. Each step updates the run row, so a failed apply leaves
 * a readable status and the manifest remains replayable.
 *
 * @license GPL-2.0-or-later
 */
final class DatasetApplyService {

	private DatasetStore $store;
	private DatasetItemWriter $writer;
	private DatasetPageRenderer $renderer;

	public function __construct(
		DatasetStore $store,
		DatasetItemWriter $writer,
		DatasetPageRenderer $renderer
	) {
		$this->store = $store;
		$this->writer = $writer;
		$this->renderer = $renderer;
	}

	/**
	 * @param array<string,mixed> $manifest
	 * @param array<string,mixed> $report
	 * @return array<string,mixed>
	 */
	public function apply(
		array $manifest,
		string $manifestHash,
		int $manifestRevision,
		string $runId,
		UserIdentity $user,
		array $report = []
	): array {
		$datasetId = (string)$manifest['dataset']['dataset_id'];
		$this->store->updateRun( $runId, [ 'step' => 'store' ] );
		$counts = $this->store->applyManifest( $manifest, $manifestHash, $manifestRevision );
		$this->store->updateRun( $runId, [ 'step' => 'wikibase' ] );
		$items = $this->writer->apply( $manifest, $user );
		$this->store->updateRun( $runId, [ 'step' => 'page' ] );
		$page = $this->renderer->save( $manifest, $user );
		$actual = $this->store->countsForDataset( $datasetId );
		$status = 'ok';
		$warnings = [];
		foreach ( [ 'tables', 'panels', 'columns', 'observations', 'footnotes' ] as $key ) {
			if ( (int)( $actual[$key] ?? 0 ) !== (int)( $counts[$key] ?? 0 ) ) {
				$status = 'partial';
				$warnings[] = "$key rows differ: stored " . ( $actual[$key] ?? 0 )
					. ', expected ' . ( $counts[$key] ?? 0 );
			}
		}
		$this->store->updateRun( $runId, [
			'status' => $status,
			'step' => 'done',
			'page_title' => $page['title'],
			'revision' => $page['revision'],
			'row_counts' => json_encode( $actual, JSON_UNESCAPED_SLASHES ),
			'report_json' => $report === [] ? null : json_encode( $report, JSON_UNESCAPED_SLASHES ),
		] );
		$title = Title::newFromText( $page['title'] );
		if ( $title !== null ) {
			$title->invalidateCache();
			MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title )->doPurge();
		}
		return [
			'status' => $status,
			'runId' => $runId,
			'pageTitle' => $page['title'],
			'pageRevision' => $page['revision'],
			'datasetItem' => $items['datasetItem'],
			'tableItems' => array_values( $items['tableItems'] ),
			'rowCounts' => $actual,
			'warnings' => $warnings,
		];
	}
}
