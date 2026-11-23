<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Law;

use EmbeddableContent\Flow\LawItemWriter;
use EmbeddableContent\Store\LawStore;
use FilesystemIterator;
use MediaWiki\User\UserIdentity;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Imports an extracted DILA LEGI tree into the `wb_law_*` store.
 *
 * The archive extraction is an operational step (`tar -xzf`); this class
 * only reads the extracted tree. One article XML gives the law, the section
 * path and the article row, so the walk is a single pass over `.../article/`.
 *
 * `--codes-only` keeps the v1 pilot to the 73 in-force codes; the baseline
 * still contains the whole LEGI tree, and a later run can ingest the rest.
 *
 * @license GPL-2.0-or-later
 */
final class LawImporter {

	private LawStore $store;

	private LegiParser $parser;

	private ?LawItemWriter $writer;

	public function __construct( LawStore $store, LegiParser $parser, ?LawItemWriter $writer = null ) {
		$this->store = $store;
		$this->parser = $parser;
		$this->writer = $writer;
	}

	/**
	 * @param array{codes_only?:bool,limit?:int,create_items?:bool,dry_run?:bool} $options
	 * @return array<string,int> stats
	 * @throws LawImportException
	 */
	public function run( string $dir, string $mode, array $options, ?UserIdentity $user, callable $log ): array {
		$dir = rtrim( $dir, '/' );
		if ( !is_dir( $dir ) ) {
			throw new LawImportException( "not a directory: $dir" );
		}
		$codesOnly = (bool)( $options['codes_only'] ?? false );
		$limit = (int)( $options['limit'] ?? 0 );
		$createItems = (bool)( $options['create_items'] ?? false );
		$dryRun = (bool)( $options['dry_run'] ?? false );
		if ( !$dryRun && !$this->store->schemaExists() ) {
			throw new LawImportException( 'law schema missing — run installLawSchema.php first' );
		}
		$root = $codesOnly ? $this->codesRoot( $dir ) : $dir;
		$log( "import root: $root (mode=$mode, codes_only=" . ( $codesOnly ? '1' : '0' ) . ', dry_run=' . ( $dryRun ? '1' : '0' ) . ')' );

		$runId = $mode . '-' . gmdate( 'YmdHis' ) . '-' . substr( hash( 'sha256', $root ), 0, 8 );
		if ( !$dryRun ) {
			$this->store->createRun( $runId, 'dila', $mode );
		}

		$store = $this->store;
		$stats = [ 'files' => 0, 'laws' => 0, 'sections' => 0, 'articles' => 0, 'skipped' => 0, 'errors' => 0 ];
		try {
			$laws = [];
			$sections = [];
			$articles = [];
			$now = wfTimestamp( TS_MW );
			$lawKeys = [];

			$flush = function () use ( &$laws, &$sections, &$articles, $store, $dryRun ): void {
				if ( $dryRun ) {
					$laws = [];
					$sections = [];
					$articles = [];
					return;
				}
				foreach ( $laws as $law ) {
					$store->upsertLaw( $law );
				}
				$store->upsertSections( array_values( $sections ) );
				$store->upsertArticles( array_values( $articles ) );
				$laws = [];
				$sections = [];
				$articles = [];
			};

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				$path = $file->getPathname();
				if ( !str_ends_with( $path, '.xml' ) || !str_contains( $path, '/article/' ) ) {
					continue;
				}
				if ( $limit > 0 && $stats['articles'] >= $limit ) {
					break;
				}
				$stats['files']++;
				$xml = file_get_contents( $path );
				if ( $xml === false ) {
					$stats['errors']++;
					$log( "unreadable: $path" );
					continue;
				}
				try {
					$parsed = $this->parser->parseArticle( $xml );
				} catch ( LegiParseException $e ) {
					$stats['errors']++;
					$log( 'parse error: ' . $path . ': ' . $e->getMessage() );
					continue;
				}
				if ( $parsed === null ) {
					$stats['skipped']++;
					continue;
				}
				$law = $parsed['law'];
				if ( $codesOnly && ( $law['nature'] ?? '' ) !== 'CODE' ) {
					$stats['skipped']++;
					continue;
				}
				$law['retrieved_at'] = $now;
				$laws[$law['law_key']] = $law;
				$lawKeys[$law['law_key']] = $law['law_key'];

				$sort = 0;
				foreach ( $parsed['sections'] as $section ) {
					$section['law_key'] = $law['law_key'];
					$section['sort_order'] = $sort++;
					$sections[$section['section_id']] = $section;
					$stats['sections']++;
				}

				$article = $parsed['article'];
				$article['retrieved_at'] = $now;
				$article['content_hash'] = hash( 'sha256', (string)$article['text'] );
				$article['created_at'] = $now;
				$article['updated_at'] = $now;
				$key = $article['law_key'] . "\0" . $article['reference_code'] . "\0" . $article['language'];
				if ( !isset( $articles[$key] ) ) {
					$stats['articles']++;
				}
				$articles[$key] = $article;
				if ( count( $articles ) >= 200 ) {
					$flush();
				}
			}
			$flush();

			if ( $mode === 'increment' ) {
				$stats += $this->applySuppressions( $dir, $dryRun, $log );
			}

			if ( $createItems && !$dryRun && $this->writer !== null && $user !== null ) {
				foreach ( $lawKeys as $lawKey ) {
					$law = $this->store->getLaw( $lawKey );
					if ( $law === null || trim( (string)( $law['item_id'] ?? '' ) ) !== '' ) {
						continue;
					}
					$id = $this->writer->ensureLawItem( $law, $user );
					if ( $id !== null ) {
						$this->store->setLawItemId( $lawKey, $id );
					}
				}
			}
			$stats['laws'] = count( $lawKeys );

			if ( !$dryRun ) {
				$this->store->finishRun( $runId, 'complete', $stats );
			}
		} catch ( \Throwable $e ) {
			if ( !$dryRun ) {
				try {
					$this->store->finishRun( $runId, 'failed', $stats, $e->getMessage() );
				} catch ( \Throwable $recordError ) {
					$log( 'failed to record the import run: ' . $recordError->getMessage() );
				}
			}
			throw $e;
		}
		$log( 'import complete: ' . json_encode( $stats ) );
		return $stats;
	}

	/**
	 * @return array<string,int>
	 */
	private function applySuppressions( string $dir, bool $dryRun, callable $log ): array {
		$file = $dir . '/liste_suppression_legi_dossier.dat';
		if ( !is_file( $file ) ) {
			return [];
		}
		$contents = file_get_contents( $file );
		if ( $contents === false ) {
			$log( "unreadable suppression list: $file" );
			return [];
		}
		$stats = [ 'deleted_laws' => 0, 'deleted_sections' => 0, 'deleted_articles' => 0 ];
		foreach ( $this->parser->parseSuppressions( $contents ) as $entry ) {
			if ( $dryRun ) {
				continue;
			}
			switch ( $entry['type'] ) {
				case 'law':
					$this->store->deleteLaw( $entry['id'] );
					$stats['deleted_laws']++;
					break;
				case 'section':
					$this->store->deleteSection( $entry['id'] );
					$stats['deleted_sections']++;
					break;
				default:
					$this->store->deleteArticleById( $entry['id'] );
					$stats['deleted_articles']++;
			}
		}
		return $stats;
	}

	private function codesRoot( string $dir ): string {
		foreach ( [
			$dir . '/legi/global/code_et_TNC_en_vigueur/code_en_vigueur',
			$dir . '/code_en_vigueur',
		] as $candidate ) {
			if ( is_dir( $candidate ) ) {
				return $candidate;
			}
		}
		return $dir;
	}
}
