<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Law;

use SimpleXMLElement;

/**
 * Parses the DILA LEGI XML used by the OpenData archives
 * (https://echanges.dila.gouv.fr/OPENDATA/LEGI/).
 *
 * One article XML carries everything the corpus needs: the article id, its
 * reference code, state and dates, the current text, and the law/section
 * context. That is why the importer walks article files and does not need to
 * traverse the law/section trees.
 *
 * Pure PHP (SimpleXML only) so the parser is unit-testable without MediaWiki.
 *
 * @license GPL-2.0-or-later
 */
final class LegiParser implements LawSourceParser {

	public function accepts( string $path ): bool {
		return str_ends_with( $path, '.xml' ) && str_contains( $path, '/article/' );
	}

	/**
	 * @return array{law:array<string,mixed>,sections:array<int,array<string,mixed>>,article:array<string,mixed>}|null
	 */
	public function parse( string $xml, string $path ): ?array {
		return $this->parseArticle( $xml );
	}

	/**
	 * @return array{law:array<string,mixed>,sections:array<int,array<string,mixed>>,article:array<string,mixed>}|null
	 *  Null when the document is not a citable article (no id / reference).
	 * @throws LegiParseException
	 */
	public function parseArticle( string $xml ): ?array {
		$doc = $this->load( $xml );
		if ( !isset( $doc->META, $doc->META->META_COMMUN, $doc->META->META_SPEC->META_ARTICLE ) ) {
			throw new LegiParseException( 'missing META/META_ARTICLE' );
		}

		$articleId = trim( (string)$doc->META->META_COMMUN->ID );
		$meta = $doc->META->META_SPEC->META_ARTICLE;
		$reference = trim( (string)$meta->NUM );
		if ( $articleId === '' || $reference === '' ) {
			return null;
		}

		$context = $doc->CONTEXTE->TEXTE ?? null;
		if ( $context === null ) {
			throw new LegiParseException( 'missing CONTEXTE/TEXTE' );
		}

		$titles = [];
		foreach ( $context->TITRE_TXT as $title ) {
			$id = trim( (string)$title['id_txt'] );
			if ( $id !== '' ) {
				$titles[$id] = [
					'title' => trim( (string)$title ),
					'short' => trim( (string)$title['c_titre_court'] ),
					'start' => $this->date( (string)$title['debut'] ),
					'end' => $this->date( (string)$title['fin'] ),
				];
			}
		}
		$lawKey = $this->lawKeyFromTitles( $titles );
		if ( $lawKey === '' ) {
			throw new LegiParseException( 'no LEGITEXT title id' );
		}
		$lawTitle = $titles[$lawKey] ?? $titles[array_key_first( $titles )];
		$nature = trim( (string)$context['nature'] );
		$jorft = trim( (string)$context['cid'] );
		$nor = trim( (string)$context['nor'] );

		$sections = [];
		$path = [];
		$deepestPath = [];
		$sectionId = null;
		$walk = function ( SimpleXMLElement $tm, int $depth, ?string $parent ) use ( &$walk, &$sections, &$path, &$deepestPath, &$sectionId ): void {
			foreach ( $tm->TITRE_TM as $title ) {
				$id = trim( (string)$title['id'] );
				$text = trim( (string)$title );
				if ( $id === '' || $text === '' ) {
					continue;
				}
				$path[] = $text;
				$sections[] = [
					'section_id' => $id,
					'parent_id' => $parent,
					'level' => $depth,
					'title' => $text,
					'path' => implode( ' > ', $path ),
				];
				$sectionId = $id;
				$deepestPath = $path;
				foreach ( $tm->TM as $child ) {
					$walk( $child, $depth + 1, $id );
				}
				array_pop( $path );
			}
		};
		foreach ( $context->TM as $tm ) {
			$walk( $tm, 1, null );
		}

		$content = isset( $doc->BLOC_TEXTUEL->CONTENU ) ? (string)$doc->BLOC_TEXTUEL->CONTENU->asXML() : '';
		$text = self::plainText( $content );
		$status = strtoupper( trim( (string)$meta->ETAT ) ) ?: 'VIGUEUR';
		// The article state is the ARTICLE's state, not the law's. The
		// code_en_vigueur/TNC_en_vigueur archives contain in-force texts only.
		$lawStatus = 'in_force';
		$sourceUrl = $nature === 'CODE'
			? 'https://www.legifrance.gouv.fr/codes/texte_lc/' . rawurlencode( $lawKey ) . '/'
			: ( $jorft !== '' ? 'https://www.legifrance.gouv.fr/loda/id/' . rawurlencode( $jorft ) . '/' : null );
		$articleUrl = $nature === 'CODE'
			? 'https://www.legifrance.gouv.fr/codes/article_lc/' . rawurlencode( $articleId ) . '/'
			: 'https://www.legifrance.gouv.fr/loda/article_lc/' . rawurlencode( $articleId ) . '/';

		return [
			'law' => [
				'law_key' => $lawKey,
				'jurisdiction' => 'FR',
				'title' => $lawTitle['title'] ?? $lawKey,
				'short_title' => $lawTitle['short'] ?? null,
				'nature' => $nature !== '' ? $nature : null,
				'nor' => $nor !== '' ? $nor : null,
				'source_url' => $sourceUrl,
				'source' => 'legi',
				'language' => 'fr',
				'version_start' => $lawTitle['start'] ?? null,
				'version_end' => $lawTitle['end'] ?? null,
				'status' => $lawStatus,
			],
			'sections' => $sections,
			'article' => [
				'law_key' => $lawKey,
				'reference_code' => $reference,
				'language' => 'fr',
				'article_id' => $articleId,
				'section_id' => $sectionId,
				'section_path' => $deepestPath === [] ? null : implode( ' > ', $deepestPath ),
				'text' => $text,
				'status' => $status,
				'version_start' => $this->date( (string)$meta->DATE_DEBUT ),
				'version_end' => $this->date( (string)$meta->DATE_FIN ),
				'source_url' => $articleUrl,
				'search_text' => trim( $reference . ' ' . implode( ' > ', $deepestPath ) . ' ' . mb_substr( $text, 0, 500 ) ),
			],
		];
	}

	/**
	 * Parses the daily `liste_suppression_legi_dossier.dat`.
	 *
	 * @return array<int,array{type:string,id:string}>
	 */
	public function parseSuppressions( string $contents ): array {
		$out = [];
		foreach ( preg_split( '/\R/', $contents ) ?: [] as $line ) {
			$line = trim( $line );
			if ( $line === '' || $line[0] === '#' ) {
				continue;
			}
			$path = preg_split( '/\s+/', $line )[0] ?? '';
			if ( preg_match( '/\b(LEGIARTI|LEGISCTA|LEGITEXT)\d+\b/', $path, $m ) !== 1 ) {
				continue;
			}
			$type = match ( $m[1] ) {
				'LEGIARTI' => 'article',
				'LEGISCTA' => 'section',
				default => 'law',
			};
			$out[] = [ 'type' => $type, 'id' => $m[0] ];
		}
		return $out;
	}

	/**
	 * Converts a LEGI HTML fragment (`<p>`, `<br/>`, entities) to plain text,
	 * preserving paragraph breaks and stripping markup.
	 */
	public static function plainText( string $html ): string {
		$text = preg_replace( '#<br\s*/?>#i', "\n", $html ) ?? $html;
		$text = preg_replace( '#</p\s*>#i', "\n\n", $text ) ?? $text;
		$text = strip_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\u{00A0}", ' ', $text );
		$text = preg_replace( '/[ \t]+/u', ' ', $text ) ?? $text;
		$text = preg_replace( '/ *\n */u', "\n", $text ) ?? $text;
		$text = preg_replace( "/\n{3,}/u", "\n\n", $text ) ?? $text;
		return trim( $text );
	}

	/**
	 * @throws LegiParseException
	 */
	private function load( string $xml ): SimpleXMLElement {
		$previous = libxml_use_internal_errors( true );
		$doc = simplexml_load_string( $xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA );
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( $doc === false ) {
			$message = $errors[0]->message ?? 'invalid XML';
			throw new LegiParseException( trim( $message ) );
		}
		return $doc;
	}

	/**
	 * @param array<string,array<string,mixed>> $titles
	 */
	private function lawKeyFromTitles( array $titles ): string {
		foreach ( array_keys( $titles ) as $id ) {
			if ( str_starts_with( $id, 'LEGITEXT' ) ) {
				return $id;
			}
		}
		return '';
	}

	private function date( string $value ): ?string {
		$value = trim( $value );
		if ( $value === '' || $value === '2999-01-01' ) {
			return null;
		}
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) === 1 ? $value : null;
	}
}
