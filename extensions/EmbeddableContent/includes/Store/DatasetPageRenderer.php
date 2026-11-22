<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Store;

use MediaWiki\Content\WikitextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use RuntimeException;

/**
 * Renders and saves the human-readable `Dataset:<slug>` view page from a
 * manifest. The page is a view: the parser functions read the measurement
 * store, and the manifest page remains the source of truth.
 *
 * @license GPL-2.0-or-later
 */
final class DatasetPageRenderer {

	/**
	 * @param array<string,mixed> $manifest
	 */
	public function render( array $manifest ): string {
		$dataset = $manifest['dataset'];
		$lines = [ '{| class="wikitable wb-dataset-infobox" style="float:right;clear:right;width:320px;margin:0 0 1em 1em;"' ];
		$lines[] = '! colspan="2" | ' . $this->escape( (string)$dataset['title'] );
		$rows = [
			'Dataset ID' => (string)$dataset['dataset_id'],
			'Source' => $this->sourceLink( (string)( $dataset['source_page'] ?? '' ) ),
			'DOI' => (string)( $dataset['doi'] ?? '' ),
			'Year' => (string)( $dataset['year'] ?? '' ),
			'Licence' => (string)( $dataset['licence_label'] ?? $dataset['licence'] ?? '' ),
			'Rights basis' => (string)( $dataset['rights_basis_label'] ?? $dataset['rights_basis'] ?? '' ),
			'Access' => (string)( $dataset['access_tier'] ?? 'public' ),
			'Status' => (string)( $dataset['status'] ?? 'draft' ),
			'Curator' => (string)( $dataset['curator'] ?? '' ),
		];
		foreach ( $rows as $label => $value ) {
			if ( $value === '' ) {
				continue;
			}
			$lines[] = '|-';
			$lines[] = '! ' . $label;
			$lines[] = '| ' . $value;
		}
		$lines[] = '|}';
		$lines[] = '';
		if ( (string)( $dataset['description'] ?? '' ) !== '' ) {
			$lines[] = $this->escape( (string)$dataset['description'] );
			$lines[] = '';
		}
		foreach ( $manifest['tables'] as $table ) {
			$title = 'Table ' . $this->escape( (string)$table['table_number'] );
			$caption = trim( (string)$table['caption'] );
			if ( $caption !== '' ) {
				$title .= ' — ' . $this->escape( $caption );
			}
			$lines[] = '== ' . $title . ' ==';
			$lines[] = '';
			$lines[] = '{{#dataset-provenance:' . $table['table_id'] . '}}';
			$panels = $table['panels'] ?? [ [ 'panel_key' => 'p1' ] ];
			$multi = count( $panels ) > 1;
			foreach ( $panels as $panel ) {
				if ( $multi ) {
					$label = trim( (string)( $panel['panel_label'] ?? '' ) );
					$lines[] = '=== ' . $this->escape( $label !== '' ? $label : (string)$panel['panel_key'] ) . ' ===';
					$lines[] = '';
				}
				$lines[] = '{{#dataset-table:' . $table['table_id'] . '|panel=' . $panel['panel_key'] . '}}';
			}
			$lines[] = '{{#dataset-footnotes:' . $table['table_id'] . '}}';
			$lines[] = '';
		}
		$lines[] = '== Cite this dataset ==';
		$lines[] = '';
		$lines[] = '{{#dataset-cite:' . $dataset['dataset_id'] . '}}';
		$lines[] = '';
		return implode( "\n", $lines );
	}

	/**
	 * @param array<string,mixed> $manifest
	 * @return array{title:string,revision:int}
	 */
	public function save( array $manifest, UserIdentity $user ): array {
		$datasetId = (string)$manifest['dataset']['dataset_id'];
		$title = Title::newFromText( 'Dataset:' . $datasetId );
		if ( $title === null ) {
			throw new RuntimeException( "invalid Dataset: title for $datasetId" );
		}
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$flags = $title->exists() ? EDIT_UPDATE : EDIT_NEW;
		$status = $page->doUserEditContent(
			new WikitextContent( $this->render( $manifest ) ),
			$user,
			'dataset view page: ' . $datasetId,
			$flags
		);
		if ( !$status->isOK() ) {
			throw new RuntimeException( 'dataset page save failed: ' . $status->getWikiText() );
		}
		$value = $status->getValue();
		$revision = is_object( $value ) && method_exists( $value, 'getId' ) ? (int)$value->getId() : 0;
		return [ 'title' => $title->getPrefixedText(), 'revision' => $revision ];
	}

	private function sourceLink( string $page ): string {
		if ( $page === '' ) {
			return '';
		}
		$title = Title::newFromText( $page );
		if ( $title === null ) {
			return $this->escape( $page );
		}
		$target = str_replace( '|', '&#124;', $title->getPrefixedText() );
		return '[[' . $target . ']]';
	}

	private function escape( string $value ): string {
		$value = str_replace( [ "\r", "\n" ], ' ', $value );
		return str_replace(
			[ '&', '<', '>', '|', '{', '}', '[', ']' ],
			[ '&amp;', '&lt;', '&gt;', '&#124;', '&#123;', '&#125;', '&#91;', '&#93;' ],
			$value
		);
	}
}
