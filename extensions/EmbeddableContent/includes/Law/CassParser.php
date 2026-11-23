<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Law;

/**
 * Parses a DILA CASS decision
 * (https://echanges.dila.gouv.fr/OPENDATA/CASS/).
 *
 * One XML file is one Cour de cassation (or Tribunal des conflits) decision.
 * The whole decision is the citable unit (`reference_code` = `arrêt` /
 * `avis` / `ordonnance` / `décision`); the DILA XML carries no official
 * paragraph numbering.
 *
 * @license GPL-2.0-or-later
 */
final class CassParser extends LawXmlParser {

	public function accepts( string $path ): bool {
		return str_ends_with( $path, '.xml' ) && str_contains( $path, '/JURI/TEXT/' );
	}

	public function parse( string $xml, string $path ): ?array {
		$doc = $this->load( $xml );
		if ( $doc->getName() !== 'TEXTE_JURI_JUDI' ) {
			return null;
		}
		$id = $this->text( $doc->META->META_COMMUN->ID ?? null );
		$meta = $doc->META->META_SPEC->META_JURI ?? null;
		$judi = $doc->META->META_SPEC->META_JURI_JUDI ?? null;
		if ( $id === '' || $meta === null ) {
			throw new LawParseException( 'missing decision id or META_JURI' );
		}
		$title = $this->text( $meta->TITRE );
		$date = $this->text( $meta->DATE_DEC );
		$nature = $this->text( $doc->META->META_COMMUN->NATURE );
		$numero = $this->text( $meta->NUMERO );
		$caseNumber = '';
		foreach ( $judi?->NUMEROS_AFFAIRES?->NUMERO_AFFAIRE ?? [] as $num ) {
			$caseNumber = $this->text( $num );
			if ( $caseNumber !== '' ) {
				break;
			}
		}
		if ( $caseNumber === '' ) {
			$caseNumber = $numero;
		}
		$reference = match ( $nature ) {
			'ARRET' => 'arrêt',
			'AVIS' => 'avis',
			'ORDONNANCE' => 'ordonnance',
			default => 'décision',
		};
		$sourceUrl = 'https://www.legifrance.gouv.fr/juri/id/' . rawurlencode( $id );
		$law = [
			'law_key' => $id,
			'jurisdiction' => 'FR',
			'title' => $title !== '' ? $title : ( 'Cour de cassation, ' . $reference . ' du ' . $this->frenchDate( $date ) ),
			'short_title' => $title,
			'nature' => $nature !== '' ? $nature : null,
			'nor' => null,
			'number' => $caseNumber !== '' ? $caseNumber : null,
			'court' => $this->text( $meta->JURIDICTION ) ?: null,
			'source_url' => $sourceUrl,
			'source' => 'cass',
			'language' => 'fr',
			'version_start' => $this->date( $date ),
			'version_end' => null,
			'status' => 'in_force',
		];

		$content = isset( $doc->TEXTE->BLOC_TEXTUEL->CONTENU )
			? (string)$doc->TEXTE->BLOC_TEXTUEL->CONTENU->asXML()
			: '';
		$text = $this->plainText( $content );
		if ( $text === '' ) {
			return null;
		}
		$article = [
			'law_key' => $id,
			'reference_code' => $reference,
			'language' => 'fr',
			'article_id' => $id,
			'section_id' => null,
			'section_path' => null,
			'text' => $text,
			'status' => 'in_force',
			'version_start' => $this->date( $date ),
			'version_end' => null,
			'source_url' => $sourceUrl,
			'search_text' => trim( $reference . ' ' . mb_substr( $text, 0, 500 ) ),
		];
		return [ 'law' => $law, 'sections' => [], 'article' => $article ];
	}

	private function date( string $value ): ?string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) === 1 ? $value : null;
	}
}
